<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentLimitExceededException;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\Payment;
use App\Models\PosTransaction;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingServicePriceResolver;
use App\Services\GroomingPaymentSettlementService;
use App\Services\GroomingProductSaleService;
use App\Services\QrPaymentVerificationService;
use App\Services\PayMongoService;
use Illuminate\Support\Str;
use App\Support\PaymentAmountLimit;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private function validatePayload(Request $request, bool $online = false): array
    {
        $data = $request->validate([
            'final_price' => $online ? 'exclude' : 'required|numeric|decimal:0,2|min:0',
            'amount_paid' => $online ? 'exclude' : 'nullable|numeric|decimal:0,2|min:0',
            'payment_method' => $online ? 'nullable|in:online' : 'nullable|in:cash,qrph',
            'notes' => 'nullable|string|max:500',
            'service_prices' => 'nullable|array',
            'service_prices.*.booking_service_id' => 'required_with:service_prices|integer',
            'service_prices.*.amount' => ['required_with:service_prices', 'numeric', 'decimal:0,2', 'min:0.01', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'pet_sizes' => 'nullable|array',
            'pet_sizes.*.booking_pet_id' => 'required_with:pet_sizes|integer',
            'pet_sizes.*.size' => 'required_with:pet_sizes|in:small,medium,large,extra_large',
            'products' => 'nullable|array',
            'products.*.item_id' => 'required|integer|distinct|exists:inventory_items,item_id',
            'products.*.quantity' => 'required|integer|min:1|max:99999999',
        ] + QrPaymentVerificationService::rules(), [], [
            'external_reference' => 'Payment Reference No.', 'transaction_at' => 'receipt transaction time',
        ]);

        if ((float) ($data['final_price'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('final price');
        }

        if ((float) ($data['amount_paid'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('amount paid');
        }

        if (array_key_exists('amount_paid', $data) && (float) $data['final_price'] > 0) {
            $maximum = PaymentAmountLimit::maximumFor((float) $data['final_price']);
            if ((float) $data['amount_paid'] > $maximum) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount paid cannot exceed '
                        ."\u{20B1}".number_format($maximum, 2).'.',
                ]);
            }
        }

        foreach ($data['service_prices'] ?? [] as $servicePrice) {
            if ((float) ($servicePrice['amount'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
                throw new PaymentLimitExceededException('service price');
            }
        }

        return $data;
    }

    private function updateBookingServicePrices(
        Booking $booking,
        array $servicePrices,
        array $editableBookingPetIds,
        array $petSizes = [],
    ): array {
        $submittedSizes = collect($petSizes)->mapWithKeys(fn ($petSize) => [
            (int) $petSize['booking_pet_id'] => $petSize['size'],
        ]);
        $editableServices = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->whereIn('booking_pet_id', $editableBookingPetIds)
            ->with(['service', 'bookingPet'])
            ->lockForUpdate()
            ->get()
            ->keyBy('booking_service_id');
        $editableServiceIds = $editableServices->keys()->map(fn ($id) => (int) $id);
        $allServiceIds = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->pluck('booking_service_id')
            ->map(fn ($id) => (int) $id);
        $submittedPrices = collect($servicePrices)->mapWithKeys(fn ($line) => [
            (int) $line['booking_service_id'] => $this->paymentReadiness()->centsToMoney(
                $this->paymentReadiness()->moneyToCents($line['amount']),
            ),
        ]);

        if ($submittedPrices->keys()->diff($allServiceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'One or more service prices do not belong to this visit.',
            ]);
        }

        if ($submittedPrices->keys()->diff($editableServiceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'Only services for pets eligible for this payment can be changed.',
            ]);
        }

        if ($editableServiceIds->diff($submittedPrices->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'Please submit a confirmed price for every normally finished pet service.',
            ]);
        }

        $warnings = [];
        foreach ($submittedPrices as $bookingServiceId => $amount) {
            $line = $editableServices->get($bookingServiceId);
            $bookedSize = $line->bookingPet?->confirmed_size ?? $line->bookingPet?->registered_size;
            $selectedSize = $submittedSizes->get($line->booking_pet_id, $bookedSize);
            $rules = app(GroomingServicePriceResolver::class)->paymentPriceRules($line, $selectedSize);
            $minimum = $rules['min'];
            $maximum = $rules['max'];

            $cents = $this->paymentReadiness()->moneyToCents($amount);
            if ($rules['safety_max'] !== null
                && $cents > $this->paymentReadiness()->moneyToCents($rules['safety_max'])) {
                throw ValidationException::withMessages([
                    'service_prices' => 'Enter ₱'.$this->formatRuleAmount($rules['safety_max']).' or less for this service.',
                ]);
            }
            if (($minimum !== null && $cents < $this->paymentReadiness()->moneyToCents($minimum))
                || ($maximum !== null && $cents > $this->paymentReadiness()->moneyToCents($maximum))) {
                if ($rules['pricing_type'] === 'fixed') {
                    $message = 'This service has a fixed price of ₱'.$this->formatRuleAmount($minimum).'.';
                } elseif ($maximum !== null) {
                    $message = 'Enter an amount from ₱'.$this->formatRuleAmount($minimum).' to ₱'.$this->formatRuleAmount($maximum).'.';
                } else {
                    $message = 'Enter an amount of at least ₱'.$this->formatRuleAmount($minimum).'.';
                }
                throw ValidationException::withMessages(['service_prices' => $message]);
            }

            if ($rules['review_threshold'] !== null) {
                $thresholdCents = $this->paymentReadiness()->moneyToCents($rules['review_threshold']);
                if ($cents >= $thresholdCents) {
                    $comparison = $cents === $thresholdCents ? 'at' : 'above';
                    $warnings[] = [
                        'booking_service_id' => $bookingServiceId,
                        'message' => 'This amount is '.$comparison.' the Extra Large starting price of ₱'
                            .$this->formatRuleAmount($rules['review_threshold']).". Confirm the pet's size and final charge.",
                    ];
                }
            }

            BookingService::query()
                ->where('booking_id', $booking->booking_id)
                ->where('booking_service_id', $bookingServiceId)
                ->update(['price_at_booking' => $amount]);
        }

        return $warnings;
    }

    private function formatRuleAmount(string|int|float $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2), '0'), '.');
    }

    private function updateBookingPetConfirmedSizes(Booking $booking, array $petSizes): void
    {
        if ($petSizes === [] || ! Schema::hasColumn('booking_pets', 'confirmed_size')) {
            return;
        }

        $bookingPets = BookingPet::query()
            ->where('booking_id', $booking->booking_id)
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->with('pet')
            ->lockForUpdate()
            ->get()
            ->keyBy('booking_pet_id');
        $submitted = collect($petSizes)->mapWithKeys(fn ($petSize) => [
            (int) $petSize['booking_pet_id'] => $petSize['size'],
        ]);

        if ($submitted->count() !== count($petSizes)
            || $submitted->keys()->diff($bookingPets->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pet_sizes' => 'One or more confirmed pet sizes do not belong to this visit.',
            ]);
        }

        $booking->loadMissing('bookingServices.service');
        foreach ($submitted as $bookingPetId => $size) {
            $bookingPet = $bookingPets->get($bookingPetId);
            if ($bookingPet->confirmed_size && $bookingPet->confirmed_size !== $size) {
                $bookingPet->pet?->confirmClinicSize($size);
            }
            $sizeChanged = $bookingPet->confirmed_size !== $size;
            $bookingPet->confirmed_size = $size;
            $bookingPet->save();
            if ($sizeChanged) app(\App\Services\GroomingTimeEstimate::class)->recalculate($booking, $bookingPet);
        }
    }

    private function prepareGroomingPayment(Booking $booking, array $data, bool $early): array
    {
        $summary = null;
        if ($early) {
            $bookingPets = $booking->bookingPets()
                ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
                ->with('pet:pet_id,pet_name')
                ->lockForUpdate()
                ->get();
            $servicePriceWarnings = $this->updateBookingServicePrices(
                $booking,
                $data['service_prices'] ?? [],
                $bookingPets->pluck('booking_pet_id')->all(),
                $data['pet_sizes'] ?? [],
            );
            $this->updateBookingPetConfirmedSizes($booking, $data['pet_sizes'] ?? []);
            $serverTotalCents = BookingService::query()
                ->where('booking_id', $booking->booking_id)
                ->whereIn('booking_pet_id', $bookingPets->pluck('booking_pet_id'))
                ->lockForUpdate()
                ->get(['price_at_booking'])
                ->sum(fn (BookingService $line) => $this->paymentReadiness()
                    ->moneyToCents($line->price_at_booking));

            if ($serverTotalCents <= 0) {
                throw ValidationException::withMessages(['payment' => 'Pay Now requires a positive visit total.']);
            }

            [$productLines, $productCents] = $this->productSales()->prepare($data['products'] ?? []);
            $serverTotal = $this->paymentReadiness()->centsToMoney($serverTotalCents + $productCents);
        } else {
            $summary = $this->paymentReadiness()->summarize($booking, true);
            if (! $summary['payment_ready']) {
                throw ValidationException::withMessages(['payment' => 'Final payment is unavailable. '.$summary['payment_blocked_reason']]);
            }

            $finishedBookingPetIds = collect($summary['pets'])
                ->where('payment_kind', 'finished')
                ->pluck('booking_pet_id')
                ->all();
            $servicePriceWarnings = $this->updateBookingServicePrices(
                $booking,
                $data['service_prices'] ?? [],
                $finishedBookingPetIds,
                $data['pet_sizes'] ?? [],
            );
            $this->updateBookingPetConfirmedSizes($booking, $data['pet_sizes'] ?? []);

            $summary = $this->paymentReadiness()->summarize($booking, true);
            [$productLines, $productCents] = $this->productSales()->prepare($data['products'] ?? []);
            $serverTotal = $this->paymentReadiness()->centsToMoney(
                $this->paymentReadiness()->moneyToCents($summary['final_booking_total']) + $productCents,
            );
        }
        if ((float) $serverTotal > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('final price');
        }
        if ($this->paymentReadiness()->moneyToCents($serverTotal) <= 0) {
            throw ValidationException::withMessages(['payment' => 'A grooming payment requires a positive total.']);
        }
        return compact('serverTotal', 'summary', 'productLines', 'servicePriceWarnings');
    }

    public function store(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);
        $this->authorizeQrPayment($request, $data);

        try {
            $result = $this->withPaymentProof($request, $data, function (array $data) use ($request, $bookingId) {
                $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return $this->transactionError('Visit not found.', 404);
                }

                if ($booking->status !== 'for_payment') {
                    return $this->transactionError('Visit is not awaiting payment.', 422);
                }

                if ($this->alreadyPaid($booking)) {
                    return $this->transactionError('This visit has already been paid.', 409);
                }

                GatewayPayment::assertGroomingUnlocked($booking);
                ['serverTotal' => $serverTotal, 'summary' => $summary, 'productLines' => $productLines,
                    'servicePriceWarnings' => $servicePriceWarnings] = $this->prepareGroomingPayment($booking, $data, false);
                $this->validateSubmittedTotal($data['final_price'], $serverTotal);
                [$amountTendered, $paymentMethod, $notes] = $this->resolvePaymentInput(
                    $data,
                    $serverTotal,
                );
                $settled = $this->settleGroomingPayment($request, $data,
                    $booking, $serverTotal, $amountTendered, $paymentMethod, $notes,
                    $request->user(), false, $productLines,
                );
                $payment = $settled['payment'];

                return compact('booking', 'payment', 'summary', 'productLines', 'servicePriceWarnings');
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'This visit or external reference was paid by another request. Refresh Transactions before retrying.',
            ], 409);
        }

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'message' => 'Payment processed. Visit is ready for pickup.',
            'booking_status' => $result['booking']->status,
            'change' => $payment->change_amount,
            'final_price' => $payment->total_amount,
            'amount_paid' => $payment->amount_tendered,
            'payment_method' => $payment->payment_method,
            'payment_method_label' => $payment->methodLabel(),
            'qr_payment' => $payment->relationLoaded('qrPayment') ? $payment->qrPayment?->verificationDetails() : null,
            'paid_at' => Carbon::parse($payment->paid_at)->format($payment->payment_method === 'qrph' ? 'Y-m-d H:i:s' : 'M j, Y g:i A'),
            'payment_summary' => $result['summary'],
            'product_addons' => $this->formatProductLines($result['productLines']),
            'service_price_warnings' => $result['servicePriceWarnings'],
        ]);
    }

    public function payNow(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);
        $this->authorizeQrPayment($request, $data);

        try {
            $result = $this->withPaymentProof($request, $data, function (array $data) use ($request, $bookingId) {
                $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return $this->transactionError('Visit not found.', 404);
                }

                if (! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                    return $this->transactionError(
                        'Early payment is only available for checked-in or in-progress visits.',
                        422,
                    );
                }

                if ($this->alreadyPaid($booking)) {
                    return $this->transactionError('This visit has already been paid.', 409);
                }

                GatewayPayment::assertGroomingUnlocked($booking);
                ['serverTotal' => $serverTotal, 'productLines' => $productLines,
                    'servicePriceWarnings' => $servicePriceWarnings] = $this->prepareGroomingPayment($booking, $data, true);
                $this->validateSubmittedTotal($data['final_price'], $serverTotal);
                [$amountTendered, $paymentMethod, $notes] = $this->resolvePaymentInput(
                    $data,
                    $serverTotal,
                );
                $settled = $this->settleGroomingPayment($request, $data,
                    $booking, $serverTotal, $amountTendered, $paymentMethod, $notes,
                    $request->user(), true, $productLines,
                );
                ['payment' => $payment, 'allPetsFinished' => $allPetsFinished, 'remainingPets' => $remainingPets] = $settled;

                return compact('booking', 'payment', 'allPetsFinished', 'remainingPets', 'productLines', 'servicePriceWarnings');
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'This visit or external reference was paid by another request. Refresh Transactions before retrying.',
            ], 409);
        }

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'message' => $result['allPetsFinished']
                ? 'Payment recorded. Visit is ready for pickup.'
                : 'Early payment recorded. Visit will remain in its current schedule until every pet is finished.',
            'change' => $payment->change_amount,
            'final_price' => $payment->total_amount,
            'amount_paid' => $payment->amount_tendered,
            'payment_method' => $payment->payment_method,
            'payment_method_label' => $payment->methodLabel(),
            'qr_payment' => $payment->relationLoaded('qrPayment') ? $payment->qrPayment?->verificationDetails() : null,
            'paid_at' => Carbon::parse($payment->paid_at)->format($payment->payment_method === 'qrph' ? 'Y-m-d H:i:s' : 'M j, Y g:i A'),
            'all_pets_finished' => $result['allPetsFinished'],
            'remaining_pets' => $result['remainingPets'],
            'booking_status' => $result['booking']->status,
            'product_addons' => $this->formatProductLines($result['productLines']),
            'service_price_warnings' => $result['servicePriceWarnings'],
        ]);
    }

    public function createOnlineCheckout(Request $request, $bookingId)
    {
        $provider = app(PayMongoService::class);
        $provider->assertConfigured();
        $data = $this->validatePayload($request, true);
        $flow = $request->validate(['flow' => 'required|in:early,final'])['flow'];
        // Commit the frozen attempt BEFORE contacting PayMongo. An interrupted/ambiguous
        // create must remain locked rather than silently creating another chargeable session.
        $attempt = DB::transaction(function () use ($request, $bookingId, $data, $flow, $provider) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
            if ($this->alreadyPaid($booking)) abort(409, 'This payment has already been completed.');
            $active = GatewayPayment::forGrooming($booking)->unresolved()->lockForUpdate()->first();
            if ($active) return $active;
            $allowed = $flow === 'early' ? ['checked_in', 'in_progress'] : ['for_payment'];
            if (! in_array($booking->status, $allowed, true)) {
                throw ValidationException::withMessages(['payment' => 'This visit is not awaiting this payment.']);
            }
            $prepared = $this->prepareGroomingPayment($booking, $data, $flow === 'early');
            $receiptSummary = $prepared['summary'] ?? $this->paymentReadiness()->summarize($booking, true);
            if ($flow === 'early') {
                foreach ($receiptSummary['pets'] as &$pet) {
                    $pet['final_pet_charge'] = $pet['payment_kind'] === 'cancelled' ? '0.00' : $pet['original_pet_subtotal'];
                }
                unset($pet);
            }
            $pets = $booking->bookingPets()->orderBy('booking_pet_id')->get(['booking_pet_id', 'confirmed_size', 'registered_size']);
            foreach ($receiptSummary['pets'] as &$pet) {
                $size = $pets->firstWhere('booking_pet_id', $pet['booking_pet_id']);
                $pet['confirmed_size'] = $size?->confirmed_size ?? $size?->registered_size;
            }
            unset($pet);
            $services = $booking->bookingServices()->with(['service', 'bookingPet.pet'])
                ->whereHas('bookingPet', fn ($q) => $q->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED))->get();
            $products = collect($prepared['productLines'])->map(fn ($line) => [
                'item_id' => $line['item']->item_id, 'name' => $line['item']->item_name,
                'quantity' => $line['quantity'], 'priceCents' => $line['priceCents'], 'subtotalCents' => $line['subtotalCents'],
            ])->all();
            $lineItems = $services->map(fn ($line) => [
                'name' => ($line->bookingPet?->pet?->pet_name ?? 'Pet').' · '.($line->service?->service_name ?? 'Grooming add-on'),
                'amount' => $this->paymentReadiness()->moneyToCents($line->price_at_booking), 'currency' => 'PHP', 'quantity' => 1,
            ])->all();
            foreach ($products as $product) $lineItems[] = [
                'name' => $product['name'], 'amount' => $product['priceCents'], 'currency' => 'PHP', 'quantity' => $product['quantity'],
            ];
            $lineItems = array_values(array_filter($lineItems, fn ($line) => $line['amount'] > 0));
            $totalCents = $this->paymentReadiness()->moneyToCents($prepared['serverTotal']);
            if (collect($lineItems)->sum(fn ($line) => $line['amount'] * $line['quantity']) !== $totalCents) {
                throw ValidationException::withMessages(['payment' => 'Refresh the visit prices and try again.']);
            }
            $attempt = GatewayPayment::create([
                'provider' => 'paymongo', 'payable_type' => $booking->getMorphClass(), 'payable_id' => $booking->getKey(),
                'context' => 'grooming', 'flow' => $flow, 'reference' => 'BTH-'.Str::uuid(),
                'amount' => $prepared['serverTotal'], 'currency' => 'PHP', 'status' => 'pending',
                'livemode' => $provider->livemode(), 'initiated_by' => $request->user()?->user_id,
                'snapshot' => [
                    'payment_methods' => $provider->paymentMethods(),
                    'metadata_version' => 2, 'services' => $services->map->only(['booking_service_id', 'booking_pet_id', 'service_id', 'addon_id', 'price_at_booking'])->all(),
                    'pet_sizes' => $pets->toArray(), 'products' => $products, 'line_items' => $lineItems,
                    'total' => $prepared['serverTotal'], 'summary' => $receiptSummary, 'notes' => $data['notes'] ?? null,
                    'grooming_total' => $this->paymentReadiness()->centsToMoney($totalCents - array_sum(array_column($products, 'subtotalCents'))),
                ],
                'reconciliation_reason' => 'checkout_creation_unconfirmed',
            ]);
            app(\App\Services\OnlinePaymentService::class)->returnUrl($attempt, 'success');
            if ($products !== []) DB::table('gateway_stock_reservations')->insert(collect($products)->map(fn ($line) => [
                'gateway_payment_id' => $attempt->id, 'item_id' => $line['item_id'], 'quantity' => $line['quantity'],
            ])->all());
            return $attempt;
        }, 3);

        $attempt = app(\App\Services\OnlinePaymentService::class)->create($attempt);
        if (! $attempt->checkout_url) {
            return response()->json([...$this->onlinePaymentResponse($attempt), 'success' => false, 'message' => 'Checkout creation is unconfirmed. Check payment status before retrying.'], 502);
        }
        return response()->json($this->onlinePaymentResponse($attempt));
    }

    public function onlinePaymentStatus(Request $request, $bookingId)
    {
        $booking = Booking::findOrFail($bookingId);
        $attempt = GatewayPayment::forGrooming($booking)
            ->when($request->filled('gateway_payment'), fn ($q) => $q->whereKey($request->integer('gateway_payment')))->latest('id')->first();
        if ($attempt) app(\App\Services\OnlinePaymentService::class)->refreshStatus($attempt);
        return response()->json($attempt ? $this->onlinePaymentResponse($attempt) : ['success' => true, 'online_payment' => null]);
    }

    public function cancelOnlineCheckout($bookingId)
    {
        $booking = Booking::findOrFail($bookingId);
        abort_if($this->alreadyPaid($booking), 409, 'This payment has already been completed.');
        $attempt = GatewayPayment::forGrooming($booking)->latest('id')->first();
        if (! $attempt) return response()->json(['success' => true, 'online_payment' => null]);
        app(\App\Services\OnlinePaymentService::class)->cancel($attempt);
        return response()->json($this->onlinePaymentResponse($attempt));
    }

    private function onlinePaymentResponse(GatewayPayment $attempt): array
    {
        $response = app(\App\Services\OnlinePaymentService::class)->response($attempt);
        if ($attempt->status === 'pending') {
            $response['checkout_summary'] = $attempt->snapshot['summary'] ?? null;
            $response['checkout_products'] = $attempt->snapshot['products'] ?? [];
            $response['checkout_notes'] = $attempt->snapshot['notes'] ?? null;
        }
        $booking = Booking::with(['user', 'walkin'])->findOrFail($attempt->payable_id);
        $response['booking'] = [
            'id' => $booking->getKey(), 'bookingReference' => $booking->booking_reference, 'status' => $booking->status,
            'ownerName' => $booking->user ? trim($booking->user->first_name.' '.$booking->user->last_name)
                : trim(($booking->walkin?->fname ?? '').' '.($booking->walkin?->lname ?? '')),
            'contactNumber' => $booking->user?->phone ?? $booking->walkin?->phone, 'appointmentDate' => $booking->booking_date,
            'paymentReady' => true,
        ];
        if ($attempt->status === 'paid' && ! $attempt->reconciliation_reason) {
            $response['payment'] = [
                'final_price' => $attempt->amount, 'amount_paid' => null, 'change' => null, 'is_test' => ! $attempt->livemode,
                'payment_method' => 'online', 'payment_method_label' => 'Online Payment',
                'paid_at' => $attempt->paid_at?->format('M j, Y g:i A'), 'booking_status' => $booking->status,
                'all_pets_finished' => $booking->status === 'released', 'payment_summary' => $attempt->snapshot['summary'],
                'notes' => $attempt->snapshot['notes'],
                'product_addons' => collect($attempt->snapshot['products'])->map(fn ($line) => [
                    'itemName' => $line['name'], 'quantity' => $line['quantity'], 'subtotal' => $line['subtotalCents'] / 100,
                ])->all(),
            ];
        }
        return $response;
    }

    public function release($bookingId)
    {
        $result = DB::transaction(function () use ($bookingId) {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return $this->transactionError('Visit not found.', 404);
            }

            if ($booking->status !== 'for_payment') {
                return $this->transactionError('Visit is not in For Payment status.', 422);
            }

            if (! $booking->paid) {
                return $this->transactionError('Visit has not been paid yet.', 422);
            }

            $booking->update(['status' => 'released', 'archived_at' => null]);

            return compact('booking');
        });

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visit is ready for physical pickup.',
            'booking_status' => 'released',
        ]);
    }

    public function index(Request $request)
    {
        $hasQrPayments = Schema::hasTable('qr_payments');
        $search = trim((string) $request->query('search', ''));
        $period = (string) $request->query('period', 'day');
        $date = (string) $request->query('date', '');
        $week = (string) $request->query('week', '');
        $month = (string) $request->query('month', '');
        $year = (string) $request->query('year', '');
        $clinicOnly = $request->user()?->isVeterinarian() ?? false;
        $type = (string) $request->query('transaction_type', $clinicOnly ? 'clinic_payment' : 'all');
        if ($clinicOnly && $type !== 'clinic_payment') {
            return response()->json(['success' => false, 'message' => 'Forbidden. You do not have permission to access this resource.'], 403);
        }
        if (! in_array($type, ['all', 'grooming_payment', 'clinic_payment', 'product_sale'], true)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction type.'], 422);
        }

        if (! in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction period.'], 422);
        }
        if ($period === 'day' && $date && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction date.'], 422);
        }
        if ($period === 'week' && $week && ! $this->isValidWeekValue($week)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction week.'], 422);
        }
        if ($period === 'month' && $month && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction month.'], 422);
        }
        if ($period === 'year' && $year && ! preg_match('/^\d{4}$/', $year)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction year.'], 422);
        }

        $query = Payment::with(['booking.user', 'products'])
            ->where('payment_status', 'paid')
            ->orderBy('paid_at', 'desc');
        $this->applyPeriodFilter($query, $period, $date, $week, $month, $year);

        if ($search !== '') {
            $search = mb_strtolower($search);
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function ($query) use ($search, $nameTerms, $hasQrPayments) {
                $query->whereHas('booking.user', function ($userQuery) use ($search, $nameTerms) {
                    $userQuery->where(function ($nameQuery) use ($search, $nameTerms) {
                        $nameQuery->whereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])
                            ->orWhere(function ($fullNameQuery) use ($nameTerms) {
                                foreach ($nameTerms as $term) {
                                    $fullNameQuery->where(function ($termQuery) use ($term) {
                                        $termQuery->whereRaw('LOWER(first_name) LIKE ?', ["%{$term}%"])
                                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$term}%"]);
                                    });
                                }
                            });
                    });
                })->orWhereHas('booking.bookingPets.pet', fn ($petQuery) => $petQuery->whereRaw('LOWER(pet_name) LIKE ?', ["%{$search}%"])
                )->orWhereHas('booking', fn ($bookingQuery) => $bookingQuery->whereRaw(
                    'LOWER(booking_reference) LIKE ?', ["%{$search}%"],
                ))->orWhereHas('products', fn ($products) => $products->whereRaw('LOWER(item_name) LIKE ?', ["%{$search}%"]));
                if ($hasQrPayments) {
                    $reference = strtolower(QrPaymentVerificationService::normalizeReference($search) ?? '');
                    if ($reference !== '') $query->orWhereHas('qrPayment', fn ($qr) => $qr->whereRaw('LOWER(external_reference) LIKE ?', ["%{$reference}%"]));
                }
            });
        }

        $posQuery = PosTransaction::query();
        $this->applyPeriodFilter($posQuery, $period, $date, $week, $month, $year, 'created_at');
        if ($search !== '') {
            $posQuery->where(function ($query) use ($search) {
                $query->whereHas('items', fn ($lines) => $lines->whereRaw('LOWER(item_name) LIKE ?', ["%{$search}%"]));
                if (preg_match('/^(?:pos[-\s#]*)?(\d+)$/i', $search, $matches)) {
                    $query->orWhere('pos_id', (int) $matches[1]);
                }
            });
        }

        $clinicQuery = ClinicAppointment::query()->where('paid', true)->where('status', 'completed');
        $this->applyPeriodFilter($clinicQuery, $period, $date, $week, $month, $year);
        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $clinicQuery->where(function ($query) use ($search, $nameTerms) {
                $query->whereRaw('LOWER(appointment_reference) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('pet', fn ($pet) => $pet->whereRaw('LOWER(pet_name) LIKE ?', ["%{$search}%"]));
                foreach (['user' => ['first_name', 'last_name'], 'walkin' => ['fname', 'lname'],
                    'pet.unregisteredCustomer' => ['first_name', 'last_name']] as $relation => $columns) {
                    $query->orWhereHas($relation, function ($owner) use ($nameTerms, $columns) {
                        foreach ($nameTerms as $term) {
                            $owner->where(fn ($name) => $name->whereRaw("LOWER({$columns[0]}) LIKE ?", ["%{$term}%"])
                                ->orWhereRaw("LOWER({$columns[1]}) LIKE ?", ["%{$term}%"]));
                        }
                    });
                }
            });
        }

        if ($request->boolean('exclude_test') && Schema::hasTable('gateway_payments')) {
            foreach ([$query, $posQuery, $clinicQuery] as $financialQuery) {
                if (Schema::hasColumn($financialQuery->getModel()->getTable(), 'gateway_payment_id')) {
                    $financialQuery->whereDoesntHave('gatewayPayment', fn ($gateway) => $gateway->where('livemode', false));
                }
            }
        }

        // Page common financial fields first, then hydrate only the visible records.
        $testFlag = fn (string $table) => Schema::hasTable('gateway_payments') && Schema::hasColumn($table, 'gateway_payment_id')
            ? "CASE WHEN EXISTS (SELECT 1 FROM gateway_payments WHERE gateway_payments.id = {$table}.gateway_payment_id AND gateway_payments.livemode = 0) THEN 1 ELSE 0 END"
            : '0';
        $paymentKey = (new Payment)->getKeyName();
        $groomingRows = $query->reorder()->selectRaw("{$paymentKey} as transaction_id, 'grooming_payment' as transaction_type, paid_at, total_amount, ".$testFlag('payments')." as is_test")
            ->when(! in_array($type, ['all', 'grooming_payment'], true), fn ($q) => $q->whereRaw('1 = 0'))->toBase();
        $posRows = $posQuery->selectRaw("pos_id as transaction_id, 'product_sale' as transaction_type, created_at as paid_at, total_amount, ".$testFlag('pos_transactions')." as is_test")
            ->when(! in_array($type, ['all', 'product_sale'], true), fn ($q) => $q->whereRaw('1 = 0'))->toBase();
        $clinicRows = $clinicQuery->selectRaw("id as transaction_id, 'clinic_payment' as transaction_type, paid_at, total_amount, ".$testFlag('clinic_appointments')." as is_test")
            ->when(! in_array($type, ['all', 'clinic_payment'], true), fn ($q) => $q->whereRaw('1 = 0'))->toBase();
        $combined = DB::query()->fromSub($clinicOnly ? $clinicRows : $groomingRows->unionAll($posRows)->unionAll($clinicRows), 'financial_transactions');
        $collectionTotal = (float) (clone $combined)->where('is_test', 0)->sum('total_amount');
        $testCollectionTotal = (float) (clone $combined)->where('is_test', 1)->sum('total_amount');
        $page = (clone $combined)->orderByDesc('paid_at')->orderBy('transaction_type')->orderByDesc('transaction_id')
            ->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)));
        $rows = $page->getCollection();
        $dateGroup = "COALESCE(DATE(paid_at), 'unknown')";
        $dateTotals = (clone $combined)->whereIn(DB::raw($dateGroup), $rows->pluck('paid_at')->map(fn ($at) => $at ? substr($at, 0, 10) : 'unknown')->unique())
            ->selectRaw("{$dateGroup} as date_key, SUM(CASE WHEN is_test = 0 THEN total_amount ELSE 0 END) as total")
            ->groupByRaw($dateGroup)->pluck('total', 'date_key');
        $paymentRelations = ['booking.user', 'products', 'processedBy', 'gatewayPayment'];
        if ($hasQrPayments) $paymentRelations[] = 'qrPayment';
        $payments = Payment::with($paymentRelations)
            ->whereKey($rows->where('transaction_type', 'grooming_payment')->pluck('transaction_id'))->get()->keyBy($paymentKey);
        $sales = PosTransaction::with(['items.item', 'cashier', 'gatewayPayment'])
            ->whereKey($rows->where('transaction_type', 'product_sale')->pluck('transaction_id'))->get()->keyBy('pos_id');
        $clinicPayments = ClinicAppointment::with(['user', 'walkin', 'pet.unregisteredCustomer', 'charges', 'paidBy', 'gatewayPayment'])
            ->whereKey($rows->where('transaction_type', 'clinic_payment')->pluck('transaction_id'))->get()->keyBy('id');
        $transactions = $rows->map(fn ($row) => match ($row->transaction_type) {
            'product_sale' => $this->formatProductSale($sales->get($row->transaction_id)),
            'clinic_payment' => $this->formatClinicPayment($clinicPayments->get($row->transaction_id)),
            default => $this->formatTransaction($payments->get($row->transaction_id)),
        });

        return response()->json([
            'success' => true,
            'period' => $period,
            'date' => $date ?: null,
            'week' => $week ?: null,
            'month' => $month ?: null,
            'year' => $year ?: null,
            'transactions' => $transactions->values(),
            'total' => $page->total(),
            'collection_total' => $collectionTotal,
            'test_collection_total' => $testCollectionTotal,
            'date_totals' => $dateTotals,
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    private function applyPeriodFilter($query, string $period, string $date, string $week, string $month, string $year, string $column = 'paid_at'): void
    {
        if ($period === 'day' && $date) {
            $query->whereDate($column, $date);
        } elseif ($period === 'week' && $week) {
            [$start, $end] = $this->weekRange($week);
            $query->whereDate($column, '>=', $start->toDateString())
                ->whereDate($column, '<=', $end->toDateString());
        } elseif ($period === 'month' && $month) {
            [$selectedYear, $selectedMonth] = explode('-', $month);
            $query->whereYear($column, (int) $selectedYear)
                ->whereMonth($column, (int) $selectedMonth);
        } elseif ($period === 'year' && $year) {
            $query->whereYear($column, (int) $year);
        }
    }

    private function isValidWeekValue(string $week): bool
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
            return false;
        }

        $year = (int) $matches[1];
        $weekNumber = (int) $matches[2];

        return $year > 0
            && $weekNumber > 0
            && $weekNumber <= Carbon::create($year, 12, 28)->isoWeek();
    }

    private function weekRange(string $week): array
    {
        preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches);
        $start = Carbon::now()->setISODate((int) $matches[1], (int) $matches[2], 1)->startOfDay();

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }

    private function formatTransaction(Payment $payment): array
    {
        $booking = $payment->booking;
        $gateway = $payment->gatewayPayment;
        $summary = $gateway?->snapshot['summary'] ?? ($booking
            ? $this->paymentReadiness()->summarize($booking)
            : ['pets' => []]);
        $pets = collect($summary['pets'] ?? []);
        $petName = $pets->pluck('pet_name')->filter()->implode(', ') ?: '—';
        $serviceLabel = $pets->flatMap(fn (array $pet) => $pet['service_breakdown'] ?? [])
            ->pluck('label')->filter()->unique()->implode(', ') ?: 'Grooming';
        $productAddons = $gateway
            ? collect($gateway->snapshot['products'])->map(fn ($line) => [
                'itemName' => $line['name'], 'quantity' => $line['quantity'],
                'priceAtSale' => $line['priceCents'] / 100, 'subtotal' => $line['subtotalCents'] / 100,
            ])->values()
            : $payment->products->map(fn ($line) => [
                'itemName' => $line->item_name, 'quantity' => $line->quantity,
                'priceAtSale' => (float) $line->price_at_sale, 'subtotal' => (float) $line->subtotal,
            ])->values();
        $productTotal = (float) $productAddons->sum('subtotal');

        return [
            'id' => $payment->getKey(),
            'key' => 'grooming-'.$payment->getKey(),
            'transactionType' => 'grooming_payment',
            'transactionTypeLabel' => 'Grooming Payment',
            'processedBy' => $payment->processed_by,
            'processedByName' => $payment->processedBy
                ? trim($payment->processedBy->first_name.' '.$payment->processedBy->last_name) : null,
            'bookingId' => $booking?->booking_id,
            'reference' => $booking?->booking_reference ?? '—',
            'ownerName' => trim(($booking?->user?->first_name ?? '').' '.($booking?->user?->last_name ?? '')),
            'petName' => $petName,
            'serviceLabel' => $serviceLabel,
            'finalPrice' => (float) $payment->total_amount,
            'amountPaid' => $gateway ? null : (float) $payment->amount_tendered,
            'changeGiven' => $gateway ? null : (float) $payment->change_amount,
            'paymentMethod' => $payment->payment_method,
            'paymentProvider' => $payment->payment_provider,
            'providerMethod' => $payment->provider_method,
            'isTest' => $gateway ? ! $gateway->livemode : false,
            'reconciliationReason' => $gateway?->reconciliation_reason,
            'paymentMethodLabel' => $payment->methodLabel(),
            'qrPayment' => $payment->relationLoaded('qrPayment') ? $payment->qrPayment?->verificationDetails() : null,
            'notes' => $payment->notes,
            'paidAt' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'paidAtFormatted' => $payment->paid_at?->format('g:i A') ?? '—',
            'dateKey' => $payment->paid_at?->toDateString() ?? 'unknown',
            'paymentSummary' => $summary,
            'groomingServicesTotal' => round((float) $payment->total_amount - $productTotal, 2),
            'productAddonsTotal' => $productTotal,
            'productAddons' => $productAddons,
        ];
    }

    private function formatClinicPayment(ClinicAppointment $appointment): array
    {
        $user = $appointment->user;
        $walkin = $appointment->walkin;
        $pet = $appointment->pet;
        $petOwner = $pet?->unregisteredCustomer;
        $ownerName = $user ? trim($user->first_name.' '.$user->last_name)
            : ($walkin ? trim($walkin->fname.' '.$walkin->lname)
                : ($petOwner ? trim($petOwner->first_name.' '.$petOwner->last_name) : '—'));

        return [
            'id' => $appointment->id,
            'key' => 'clinic-'.$appointment->id,
            'transactionType' => 'clinic_payment',
            'transactionTypeLabel' => 'Clinic Payment',
            'reference' => $appointment->appointment_reference,
            'ownerName' => $ownerName,
            'petName' => $pet?->pet_name ?? '—',
            'finalPrice' => (float) $appointment->total_amount,
            'amountPaid' => $appointment->cash_received !== null ? (float) $appointment->cash_received : null,
            'changeGiven' => $appointment->cash_received !== null
                ? round((float) $appointment->cash_received - (float) $appointment->total_amount, 2) : null,
            'paymentMethod' => $appointment->payment_method,
            'isTest' => $appointment->gatewayPayment ? ! $appointment->gatewayPayment->livemode : false,
            'paymentMethodLabel' => $appointment->gatewayPayment?->methodLabel() ?? GatewayPayment::labelFor($appointment->payment_method),
            'processedBy' => $appointment->paid_by_user_id,
            'processedByName' => $appointment->paidBy?->paymentAttribution(),
            'paidAt' => $appointment->paid_at?->format('Y-m-d H:i:s'),
            'dateKey' => $appointment->paid_at?->toDateString() ?? 'unknown',
            'charges' => $appointment->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'description' => $charge->description,
                'quantity' => $charge->quantity,
                'unit_price' => $charge->unit_price,
                'amount' => $charge->amount,
            ])->values(),
        ];
    }

    private function formatProductSale(PosTransaction $pos): array
    {
        $receipt = $pos->receiptSnapshot();
        return [
            'id' => $pos->pos_id,
            'key' => 'pos-'.$pos->pos_id,
            'transactionType' => 'product_sale',
            'transactionTypeLabel' => 'Product Sale',
            'reference' => $receipt['reference'],
            'finalPrice' => (float) $pos->total_amount,
            'amountPaid' => $pos->gatewayPayment ? null : (float) $pos->amount_tendered,
            'changeGiven' => $pos->gatewayPayment ? null : (float) $pos->change_amount,
            'paymentMethod' => $receipt['payment_method'],
            'paymentMethodLabel' => $receipt['payment_method_label'],
            'isTest' => $receipt['is_test'],
            'paidAt' => $receipt['created_at'],
            'dateKey' => $pos->created_at?->toDateString(),
            'processedBy' => $pos->cashier_id,
            'processedByName' => $receipt['cashier_name'],
            'notes' => $pos->notes,
            'items' => $receipt['items'],
            'receipt' => $receipt,
        ];
    }

    private function validateSubmittedTotal(string|int|float $submitted, string $serverTotal): void
    {
        if ((float) $serverTotal > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('final price');
        }
        if ($this->paymentReadiness()->moneyToCents($submitted)
            !== $this->paymentReadiness()->moneyToCents($serverTotal)) {
            throw ValidationException::withMessages([
                'final_price' => 'The submitted total does not match the server-calculated visit total.',
            ]);
        }
    }

    private function resolvePaymentInput(
        array $data,
        string $serverTotal,
    ): array {
        $money = $this->paymentReadiness();
        $totalCents = $money->moneyToCents($serverTotal);
        if (($data['payment_method'] ?? 'cash') === 'qrph') $data['amount_paid'] = $serverTotal;
        $amountCents = $money->moneyToCents($data['amount_paid'] ?? 0);

        if ($totalCents <= 0) {
            throw ValidationException::withMessages([
                'final_price' => 'A grooming payment requires a positive total.',
            ]);
        }

        if (! array_key_exists('amount_paid', $data)) {
            throw ValidationException::withMessages(['amount_paid' => 'Amount paid is required.']);
        }
        if ($amountCents < $totalCents) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid cannot be less than the final price.',
            ]);
        }

        $maximum = PaymentAmountLimit::maximumFor($totalCents / 100);
        if ($amountCents > (int) round($maximum * 100)) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid cannot exceed ₱'.number_format($maximum, 2).'.',
            ]);
        }

        return [
            $money->centsToMoney($amountCents),
            $data['payment_method'] ?? 'cash',
            $data['notes'] ?? null,
        ];
    }

    private function alreadyPaid(Booking $booking): bool
    {
        return (bool) $booking->paid
            || Payment::query()
                ->where('booking_id', $booking->booking_id)
                ->where('payment_status', 'paid')
                ->lockForUpdate()
                ->exists();
    }

    private function transactionError(string $message, int $status): array
    {
        return ['error' => compact('message', 'status')];
    }

    private function transactionErrorResponse(array $result)
    {
        return response()->json([
            'success' => false,
            'message' => $result['error']['message'],
        ], $result['error']['status']);
    }

    private function paymentReadiness(): GroomingPaymentReadinessService
    {
        return app(GroomingPaymentReadinessService::class);
    }

    private function authorizeQrPayment(Request $request, array $data): void
    {
        if (($data['payment_method'] ?? 'cash') === 'qrph') {
            abort_unless($request->user()?->canAccessGrooming(), 403);
        }
    }

    private function settleGroomingPayment(
        Request $request, array $data, Booking $booking, string $total, string $tendered,
        string $method, ?string $notes, ?\App\Models\User $actor, bool $early, array $productLines,
    ): array {
        $qr = $method === 'qrph'
            ? app(QrPaymentVerificationService::class)->verify($booking, $data, $total, $request->user())
            : null;
        $settled = $this->paymentSettlement()->settle($booking, $total, $tendered, $method, $notes, $actor, $early, $productLines);
        if ($qr) {
            $settled['payment']->update(['qr_payment_id' => $qr->id]);
            $settled['payment']->setRelation('qrPayment', $qr);
        }
        return $settled;
    }

    private function withPaymentProof(Request $request, array $data, \Closure $settle): array
    {
        $proofs = app(\App\Services\QrPaymentProofService::class);
        $proof = ($data['payment_method'] ?? 'cash') === 'qrph' ? $proofs->store($request->file('receipt')) : [];
        $committed = false;
        try {
            $data['_proof'] = $proof;
            $result = DB::transaction(fn () => $settle($data));
            $committed = isset($result['payment']);
            return $result;
        } finally {
            if (! $committed) $proofs->discard($proof);
        }
    }

    private function paymentSettlement(): GroomingPaymentSettlementService
    {
        return app(GroomingPaymentSettlementService::class);
    }

    private function formatProductLines(array $lines): array
    {
        return collect($lines)->map(fn ($line) => [
            'itemName' => $line['item']->item_name,
            'quantity' => $line['quantity'],
            'subtotal' => $line['subtotalCents'] / 100,
        ])->all();
    }

    private function productSales(): GroomingProductSaleService
    {
        return app(GroomingProductSaleService::class);
    }
}
