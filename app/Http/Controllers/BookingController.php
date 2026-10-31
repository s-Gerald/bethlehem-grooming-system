<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\ClinicSetting;
use App\Models\Notification;
use App\Models\Pet;
use App\Models\Service;
use App\Models\TimeWindow;
use App\Models\User;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Services\AvailabilityTimeWindowService;
use App\Services\CustomerPreRegistrationAccessService;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingServicePriceResolver;
use App\Support\PetWeightSize;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private const MAX_PETS_PER_BOOKING = 10;

    // ── GET AVAILABLE TIME WINDOWS ────────────────────────
    public function getTimeslots(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    ) {
        $date = $request->query('date', Carbon::today()->toDateString());
        $settings = ClinicSetting::current();
        $availability = $settings->serviceAvailability('grooming');
        $windows = $timeWindows->preferredWindows($settings, 'grooming', $date);

        return response()->json([
            'success' => true,
            'date' => $date,
            'availability' => $availability,
            'windows' => $windows,
        ]);
    }

    // ── SUBMIT A BOOKING ──────────────────────────────────
    public function store(
        Request $request,
        GroomingServicePriceResolver $servicePrices,
        CustomerPreRegistrationAccessService $preRegistrationAccess,
    ) {
        $rules = [
            'service_day' => 'nullable|date_format:Y-m-d',
            'number_of_pets' => 'required|integer|min:1|max:'.self::MAX_PETS_PER_BOOKING,
            'special_notes' => 'nullable|string',
            ...\App\Services\GroomingConsentCapture::rules(),
            'pets' => 'required|array|min:1|max:'.self::MAX_PETS_PER_BOOKING,
            'pets.*.pet_id' => 'nullable|integer|exists:pets,pet_id',
            'pets.*.pet_name' => 'required|string|max:100',
            'pets.*.species' => ['required_without:pets.*.pet_id', 'string', 'regex:/^(dog|cat)$/i'],
            'pets.*.breed' => 'nullable|string|max:100',
            'pets.*.birthdate' => 'nullable|date',
            'pets.*.size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'pets.*.fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat(allowExistingPetSelection: true)],
            'pets.*.weight' => ['nullable', new ValidPetWeight],
            'pets.*.color' => ['nullable', 'string', new ValidPetColor(allowExistingPetSelection: true)],
            'pets.*.medical_conditions' => 'nullable|string|max:1000',
            'pets.*.special_instructions' => 'nullable|string',
            'pets.*.haircut_requested' => 'sometimes|boolean',
            'pets.*.groomer_id' => 'nullable|integer',
            'pets.*.services' => 'nullable|array',
            'pets.*.grooming_preference' => 'nullable|string|max:32',
            'pets.*.services.package' => 'nullable|string',
            'pets.*.services.ala_carte' => 'nullable|array',
            'pets.*.services.ala_carte.*' => 'nullable|string',
        ];
        // Saved pets are references. Validate supplied profile differences after ownership resolution,
        // without rejecting unchanged legacy values against today's creation rules.
        foreach ((array) $request->input('pets', []) as $index => $petData) {
            if (is_array($petData) && ! empty($petData['pet_id'])) {
                foreach (array_keys(\App\Support\PetInformation::FIELDS) as $field) {
                    $type = in_array($field, ['is_neutered', 'is_deceased'], true) ? 'boolean'
                        : ($field === 'weight' ? 'numeric' : 'string');
                    $rules["pets.{$index}.{$field}"] = ['nullable', $type];
                }
            }
        }
        $validated = $request->validate($rules);

        $validated['pets'] = array_map(
            fn (array $pet) => empty($pet['pet_id']) ? PetWeightSize::withComputedSize($pet) : $pet,
            $validated['pets'],
        );
        $request->merge(['pets' => $validated['pets']]);

        return app(\App\Services\DailyPetQueue::class)->runForDate(now('Asia/Manila')->toDateString(), function () use (
            $request,
            $servicePrices,
            $preRegistrationAccess,
        ) {

            $user = $request->user();
            $lockedUser = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
            \App\Services\CustomerPhoneVerificationService::assertPreRegistration($lockedUser);

            $access = $preRegistrationAccess->forUser((int) $user->user_id);
            if (! $access['allowed']) {
                return response()->json([
                    'success' => false,
                    'code' => 'ongoing_pre_registration',
                    'message' => $access['message'],
                    'ongoing' => $access['ongoing'],
                ], 409);
            }

            $days = app(\App\Services\RegistrationServiceDay::class);
            $date = $days->assertReviewedDay('grooming', $request->input('service_day'));
            $savedPets = Pet::where('user_id', $user->user_id)
                ->whereIn('pet_id', array_column($request->pets, 'pet_id'))
                ->where('is_archived', false)->lockForUpdate()->get()->keyBy('pet_id');
            foreach ($request->pets as $index => $petData) {
                $savedPet = $savedPets->get($petData['pet_id'] ?? null);
                if (! empty($petData['pet_id']) && ! $savedPet) {
                    throw ValidationException::withMessages(["pets.{$index}.pet_id" => 'The selected pet is unavailable.']);
                }
                if ($savedPet) {
                    $profile = array_filter(array_intersect_key($petData, \App\Support\PetInformation::FIELDS),
                        fn ($value) => \App\Support\PetInformation::meaningful($value));
                    if (isset($profile['pet_name'])) $profile['pet_name'] = Pet::normalizeName($profile['pet_name']);
                    \App\Support\PetInformation::customerAttributes($savedPet, $profile, "pets.{$index}.");
                }
                $birthdate = $savedPet ? $savedPet->birthdate : ($petData['birthdate'] ?? null);
                if ($birthdate && Carbon::parse($birthdate)->startOfDay()->addMonthsNoOverflow(6)->isAfter(Carbon::today())) {
                    throw ValidationException::withMessages([
                        "pets.{$index}.birthdate" => 'Pet must be at least 6 months old for grooming.',
                    ]);
                }
            }
            $sedationConsent = $request->boolean('sedation_consent');
            $request->merge(['pets' => app(\App\Services\PetGroomerSelection::class)->validate($request->pets, false)]);

            $duplicate = Booking::where('user_id', $user->user_id)
                ->where('booking_date', $date)
                ->whereNotIn('status', ['cancelled', 'archived', 'no_show', 'expired'])
                ->first();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a visit on this date.',
                    'errors' => [
                        'existing_booking_id' => $duplicate->booking_id,
                        'existing_booking_ref' => $duplicate->booking_reference,
                    ],
                ], 422);
            }

            // ── Generate booking reference ────────────────────
            $dateStr = Carbon::parse($date)->format('Ymd');
            $prefix = 'OR-'.$dateStr.'-';
            $maxRef = Booking::where('booking_reference', 'like', $prefix.'%')->orderByDesc('booking_id')->value('booking_reference');
            $lastCount = $maxRef ? ((int) substr($maxRef, strlen($prefix))) + 1 : 1;
            $reference = $prefix.str_pad($lastCount, 4, '0', STR_PAD_LEFT);

            $hasServiceSnapshots = Schema::hasColumn('booking_services', 'service_name_snapshot');

            // ── Create the booking ────────────────────────────
            $booking = new Booking([
                'booking_reference' => $reference,
                'user_id' => $user->user_id,
                'window_id' => null,
                'booking_date' => $date,
                'registration_closes_at' => $days->closingDeadline('grooming', $date),
                'number_of_pets' => $request->number_of_pets,
                'booking_type' => 'online',
                'status' => 'waiting_to_arrive',
                'special_notes' => $request->special_notes,
                'sedation_consent' => $sedationConsent,
                'sedation_consent_source' => $sedationConsent ? 'customer_online' : null,
                'sedation_consent_recorded_at' => $sedationConsent ? now() : null,
                'total_amount' => 0,
            ]);

            $booking->created_at = now('Asia/Manila');
            $booking->save();

            $signedConsent = \App\Services\GroomingConsentCapture::capture($booking, $request->only([
                'sedation_consent', 'signer_name', 'signature_strokes',
            ]));

            // ── Save pets ─────────────────────────────────────
            foreach ($request->pets as $index => $petData) {
                $pet = $savedPets->get($petData['pet_id'] ?? null);
                // Create a new pet record if none was found
                if (! $pet) {
                    $pet = Pet::create([
                        'user_id' => $user->user_id,
                        'pet_name' => $petData['pet_name'],
                        'species' => $petData['species'],
                        'breed' => $petData['breed'] ?? null,
                        'birthdate' => $petData['birthdate'] ?? null,
                        'weight' => $petData['weight'] ?? null,
                        'color' => ValidPetColor::validatedColor($petData),
                        'size' => PetWeightSize::sizeFor($petData['species'], $petData['weight'] ?? null)
                            ?? $petData['size'] ?? null,
                        'fur_type' => ValidBreedCoat::validatedCoat($petData),
                        'medical_conditions' => $petData['medical_conditions'] ?? null,
                    ]);
                }

                // Link pet to this booking
                $bookingPetAttributes = [
                    'booking_id' => $booking->booking_id,
                    'pet_id' => $pet->pet_id,
                    'special_instructions' => $petData['special_instructions'] ?? null,
                    'haircut_requested' => (bool) ($petData['haircut_requested'] ?? false),
                    'groomer_id' => $petData['groomer_id'],
                    'grooming_preference' => $petData['grooming_preference'] ?? null,
                ];
                if (Schema::hasColumn('booking_pets', 'registered_size')) {
                    $bookingPetAttributes['registered_size'] = $pet->groomingSize();
                }
                app(\App\Services\GroomingServiceSelection::class)->validate([...$petData, 'size' => $pet->groomingSize()],
                    $petData['services']['package'] ?? null, $petData['services']['ala_carte'] ?? [],
                    $pet->species, "pets.{$index}");
                $bookingPet = BookingPet::create($bookingPetAttributes);

                // ── Save services for this pet ────────────────
                $petSize = $pet->groomingSize();
                $slugsToSave = [];

                $packageSlug = $petData['services']['package'] ?? null;
                if ($packageSlug) {
                    $slugsToSave[] = $packageSlug;
                }

                foreach ($petData['services']['ala_carte'] ?? [] as $slug) {
                    if ($slug) {
                        $slugsToSave[] = $slug;
                    }
                }

                if (! empty($slugsToSave)) {
                    $services = Service::whereIn('slug', $slugsToSave)->get()->keyBy('slug');

                    foreach ($slugsToSave as $slug) {
                        $service = $services->get($slug);
                        if (! $service) {
                            continue;
                        }

                        $bounds = $servicePrices->bookingPriceBounds($service, $petSize);
                        $price = $bounds['max'] !== null && $bounds['min'] !== $bounds['max']
                            ? '0.00'
                            : $servicePrices->servicePrice($service, $petSize);

                        BookingService::create([
                            'booking_id' => $booking->booking_id,
                            'booking_pet_id' => $bookingPet->booking_pet_id ?? null,
                            'service_id' => $service->service_id,
                            ...($hasServiceSnapshots ? [
                                'service_name_snapshot' => $service->service_name,
                                'service_kind_snapshot' => $service->kind,
                            ] : []),
                            'addon_id' => null,
                            'price_at_booking' => $price,
                            'price_min_at_booking' => $bounds['min'],
                            'price_max_at_booking' => $bounds['max'],
                        ]);
                    }
                }
            }

            $booking->load('bookingPets.groomer');

            $schedule = $this->notificationScheduleLabel($date, null);

            Notification::create([
                'type' => 'booked',
                'booking_id' => $booking->booking_id,
                'message' => "New online registration {$reference} by {$user->first_name} {$user->last_name} on {$schedule}.",
                'is_read' => 0,
                'created_at' => now(),
            ]);

            // Reload database-managed timestamps so the confirmation uses the
            // authoritative booking creation time without changing the schedule.
            $booking->refresh();
            $createdAt = Carbon::parse(
                $booking->created_at,
                config('app.timezone')
            )->toIso8601String();

            return response()->json([
                'success' => true,
                'message' => 'Your online registration has been received. Your queue position will be assigned when you check in at the clinic.',
                'consent' => $signedConsent->only(['grooming_consent', 'sedation_consent', 'signer_name', 'signed_at', 'consent_version']),
                'booking' => [
                    'booking_id' => $booking->booking_id,
                    'booking_reference' => $booking->booking_reference,
                    'booking_date' => $booking->booking_date,
                    'window' => null,
                    'queue_number' => null,
                    'created_at' => $createdAt,
                    'status' => $booking->status,
                    'number_of_pets' => $booking->number_of_pets,
                    'pets' => $booking->bookingPets->map(fn ($bp) => ['pet_id' => $bp->pet_id,
                        'haircut_requested' => (bool) $bp->haircut_requested,
                        'groomer_id' => $bp->groomer_id, 'pet_groomer' => $bp->groomer,
                        'grooming_preference' => $bp->grooming_preference, 'grooming_preference_label' => $bp->grooming_preference_label])->values(),
                ],
            ], 201);
        });
    }

    public function preRegistrationAccess(
        Request $request,
        CustomerPreRegistrationAccessService $preRegistrationAccess,
    ) {
        return response()->json([
            'success' => true,
            ...$preRegistrationAccess->forUser((int) $request->user()->user_id),
            'sms_verification' => \App\Services\CustomerPhoneVerificationService::flags(),
            'phone_verification_required' => \App\Services\CustomerPhoneVerificationService::required($request->user()),
        ]);
    }

    // ── GET BOOKING HISTORY ───────────────────────────────
    public function history(Request $request)
    {
        $userId = $request->user()->user_id;
        app(\App\Services\PreRegistrationExpiry::class)->expire((int) $userId);
        $historyLimit = $request->has('history_limit')
            ? max(0, min(100, (int) $request->query('history_limit')))
            : null;
        $petId = null;

        if ($request->filled('pet_id')) {
            $request->validate([
                'pet_id' => ['integer', 'min:1'],
            ]);

            $petId = (int) $request->query('pet_id');
            $ownsPet = Pet::where('pet_id', $petId)
                ->where('user_id', $userId)
                ->exists();

            if (! $ownsPet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pet not found.',
                ], 404);
            }
        }

        $relations = ['timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service'];
        $hasCancellationAuditColumns = Schema::hasColumns('bookings', [
            'cancel_count',
            'cancellation_reason',
        ]);
        if (Schema::hasTable('payments')) {
            $paymentRelations = config('qr_payments.enabled', false) && Schema::hasTable('qr_payments') ? ['qrPayment'] : [];
            $relations['payments'] = fn ($query) => $query
                ->where('payment_status', 'paid')
                ->with($paymentRelations)
                ->orderByDesc('paid_at');
        }

        $activeQuery = Booking::where('user_id', $userId)
            ->neverCancelled($hasCancellationAuditColumns)
            ->whereNotIn('status', ['archived', 'expired', 'no_show']);

        if ($petId !== null) {
            $activeQuery->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $petId));
        }

        $active = $activeQuery
            ->with($relations)
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_id', 'desc')
            ->get();

        $historyQuery = Booking::where('user_id', $userId)
            ->whereIn('status', ['archived', 'cancelled', 'expired', 'no_show']);

        if ($petId !== null) {
            $historyQuery->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $petId));
        }

        $historyTotal = (clone $historyQuery)->count();

        $historyQuery
            ->with($relations)
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_id', 'desc');

        if ($historyLimit !== null) {
            $historyQuery->limit($historyLimit);
        }

        $history = $historyLimit === 0
            ? collect()
            : $historyQuery->get();

        $format = function ($b) use ($petId) {
            $bookingPets = $b->bookingPets ?? collect();
            $showGroomingTracker = true;
            $paymentSummary = (bool) $b->paid
                ? app(GroomingPaymentReadinessService::class)->summarize($b)
                : null;

            if ($petId !== null) {
                $bookingPets = $bookingPets->where('pet_id', $petId);
            }

            $pets = $bookingPets->map(function ($bp) use (
                $b,
            ) {
                $services = ($b->bookingServices ?? collect())
                    ->where('booking_pet_id', $bp->booking_pet_id)
                    ->map(fn ($bookingService) => [
                        'booking_service_id' => $bookingService->booking_service_id,
                        'service_id' => $bookingService->service_id,
                        'service_name' => $bookingService->service?->service_name,
                        'price_at_booking' => $bookingService->price_at_booking,
                        'price_min_at_booking' => $bookingService->price_min_at_booking,
                        'price_max_at_booking' => $bookingService->price_max_at_booking,
                    ])
                    ->values();

                $groomingFinished = $bp->grooming_state === BookingPet::GROOMING_STATE_FINISHED
                    || $bp->grooming_end_time !== null;
                $groomingStatus = match (true) {
                    in_array($b->status, ['cancelled', 'no_show', 'expired'], true) => $b->status,
                    $bp->grooming_state === BookingPet::GROOMING_STATE_CANCELLED => 'cancelled',
                    $bp->grooming_end_time !== null => 'grooming_finished',
                    $bp->grooming_start_time !== null => 'in_progress',
                    default => $b->status,
                };

                return [
                    'pet_id' => $bp->pet_id,
                    'booking_pet_id' => $bp->booking_pet_id,
                    'pet_queue_number' => $bp->pet_queue_number,
                    'pet_queue_date' => $bp->pet_queue_date?->toDateString(),
                    'groomer_id' => $bp->groomer_id,
                    'pet_groomer' => $bp->groomer,
                    'haircut_requested' => (bool) $bp->haircut_requested,
                    'pet_name' => $bp->pet?->pet_name ?? '—',
                    'breed' => $bp->pet?->breed ?? '—',
                    'species' => $bp->pet?->species,
                    'size' => $bp->confirmed_size ?? $bp->registered_size ?? $bp->pet?->groomingSize(),
                    'weight' => $bp->pet?->weight,
                    'registered_size' => $bp->registered_size,
                    'confirmed_size' => $bp->confirmed_size,
                    'grooming_preference' => $bp->grooming_preference, 'grooming_preference_label' => $bp->grooming_preference_label,
                    'grooming_status' => $groomingStatus,
                    'active_in_grooming' => ! $groomingFinished && $bp->grooming_state !== BookingPet::GROOMING_STATE_CANCELLED,
                    'cancellation_reason' => $bp->cancellation_reason,
                    'cancelled_at' => $bp->cancelled_at?->toIso8601String(),
                    'grooming_started_at' => $bp->grooming_start_time
                        ? Carbon::parse($bp->grooming_start_time)->format('g:i A')
                        : null,
                    'grooming_finished_at' => $bp->grooming_end_time
                        ? Carbon::parse($bp->grooming_end_time)->format('g:i A')
                        : null,
                    'grooming_started_timestamp' => $bp->grooming_start_time?->toIso8601String(),
                    'grooming_finished_timestamp' => $bp->grooming_end_time?->toIso8601String(),
                    'services' => $services,
                ];
            })->values();

            $paidPayment = $b->relationLoaded('payments')
                ? $b->payments->first()
                : null;
            $customerPaymentSummary = null;
            if ($paymentSummary && $paidPayment) {
                $safePets = collect($paymentSummary['pets']);
                if ($petId !== null) {
                    $safePets = $safePets->where('pet_id', $petId);
                }

                $customerPaymentSummary = [
                    'status' => 'paid',
                    'final_booking_total' => $paymentSummary['final_booking_total'],
                    'amount_tendered' => $paidPayment->amount_tendered,
                    'change_amount' => $paidPayment->change_amount,
                    'payment_method' => $paidPayment->payment_method,
                    'payment_method_label' => $paidPayment->methodLabel(),
                    'paid_at' => $paidPayment->paid_at?->toIso8601String(),
                    'pets' => $safePets->map(fn (array $pet) => [
                        'pet_id' => $pet['pet_id'],
                        'booking_pet_id' => $pet['booking_pet_id'],
                        'pet_name' => $pet['pet_name'],
                        'pet_species' => $pet['pet_species'],
                        'grooming_state' => $pet['grooming_state'],
                        'payment_kind' => $pet['payment_kind'],
                        'service_breakdown' => $pet['service_breakdown'],
                        'original_pet_subtotal' => $pet['original_pet_subtotal'],
                        'final_pet_charge' => $pet['final_pet_charge'],
                    ])->values()->all(),
                ];
            }

            return [
                'booking_id' => $b->booking_id,
                'booking_reference' => $b->booking_reference,
                'booking_type' => $b->booking_type,
                'booking_date' => $b->booking_date,
                'arrival_time_passed' => $b->status === 'waiting_to_arrive' && ! $b->dropped_off_at
                    && $b->booking_date === now()->toDateString() && $b->timeWindow
                    && ($b->arrival_end_time
                        ? Carbon::parse($b->booking_date.' '.$b->arrival_end_time)->lt(now())
                        : Carbon::parse($b->booking_date.' '.$b->timeWindow->end_time)->lte(now())),
                'queue_number' => $b->queue_number,
                'created_at' => $b->created_at
                    ? Carbon::parse($b->created_at, config('app.timezone'))->toIso8601String()
                    : null,
                'status' => $b->status === 'cancelled' || $b->cancel_count > 0 || trim((string) $b->cancellation_reason) !== ''
                    ? 'cancelled' : $b->status,
                'cancellation_reason' => $b->cancellation_reason,
                'cancelled_at' => $b->cancelled_at?->toIso8601String(),
                'show_grooming_tracker' => $showGroomingTracker,
                'paid' => (bool) $b->paid || ($b->relationLoaded('payments') && $b->payments->isNotEmpty()),
                'payment_summary' => $customerPaymentSummary,
                'number_of_pets' => $b->number_of_pets,
                'reschedule_count' => $b->reschedule_count ?? 0,
                'cancel_count' => $b->cancel_count ?? 0,
                'special_notes' => $b->special_notes,
                'time_window' => $b->timeWindow ? [
                    'window_id' => $b->timeWindow->window_id,
                    'window_label' => $b->preferredArrivalWindow()->window_label,
                ] : null,
                'dropped_off_at' => $b->dropped_off_at
                    ? Carbon::parse($b->dropped_off_at)->format('g:i A')
                    : null,
                'dropped_off_timestamp' => $b->dropped_off_at
                    ? Carbon::parse($b->dropped_off_at, config('app.timezone'))->toIso8601String()
                    : null,
                'grooming_started_at' => $b->grooming_started_at
                    ? Carbon::parse($b->grooming_started_at)->format('g:i A')
                    : null,
                'grooming_started_timestamp' => $b->grooming_started_at
                    ? Carbon::parse($b->grooming_started_at, config('app.timezone'))->toIso8601String()
                    : null,
                'grooming_finished_at' => $b->grooming_finished_at
                    ? Carbon::parse($b->grooming_finished_at)->format('g:i A')
                    : null,
                'grooming_finished_timestamp' => $b->grooming_finished_at
                    ? Carbon::parse($b->grooming_finished_at, config('app.timezone'))->toIso8601String()
                    : null,
                'pets' => $pets,
            ];
        };

        return response()->json([
            'success' => true,
            'bookings' => $active->map($format)->values(),
            'history' => $history->map($format)->values(),
            'history_total' => $historyTotal,
            'clinic_visits' => Schema::hasTable('clinic_appointments') ? \App\Models\ClinicAppointment::query()
                ->where('user_id', $userId)->where('status', 'waiting_to_arrive')->whereNull('checked_in_at')
                ->select(['id', 'pet_id', 'appointment_reference', 'status', 'appointment_type', 'appointment_date', 'queue_number', 'created_at', 'checked_in_at'])
                ->with('pet:pet_id,pet_name')->orderBy('created_at')->orderBy('id')
                ->get()->map(fn ($visit) => [
                    'id' => $visit->id, 'reference' => $visit->appointment_reference,
                    'status' => $visit->status, 'appointment_type' => $visit->appointment_type,
                    'service_day' => $visit->appointment_date?->toDateString(),
                    'queue_number' => $visit->queue_number,
                    'created_at' => $visit->created_at?->toIso8601String(),
                    'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
                    'pet_name' => $visit->pet?->pet_name,
                ])->values() : [],
        ]);
    }

    // Real-time client dashboard snapshot of today's grooming queue and capacity.
    public function groomingCapacity(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $activeGroomingPets = app(\App\Services\OperationalCapacity::class)->groomingPets();

        $queued = (clone $activeGroomingPets)
            ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)
            ->count();

        $inProgress = (clone $activeGroomingPets)
            ->where('grooming_state', BookingPet::GROOMING_STATE_IN_PROGRESS)
            ->count();

        $readyForPickup = Booking::where('booking_date', $today)
            ->whereIn('status', ['for_payment', 'for_pickup', 'released'])
            ->count();

        $waiting = Booking::where('booking_date', $today)
            ->where('status', 'waiting_to_arrive')
            ->count();

        $completed = Booking::whereDate('grooming_finished_at', $today)
            ->whereNotIn('status', ['cancelled', 'no_show', 'expired'])
            ->count();

        return response()->json([
            'success' => true,
            'date' => $today,
            'capacity' => app(\App\Services\OperationalCapacity::class)->snapshot(),
            'queue' => [
                'active' => $queued + $inProgress,
                'queued' => $queued,
                'in_progress' => $inProgress,
                'ready_for_pickup' => $readyForPickup,
                'waiting' => $waiting,
                'completed_today' => $completed,
            ],
            'updated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    // ── GET SINGLE BOOKING ────────────────────────────────
    public function show(Request $request, $id)
    {
        $booking = Booking::where('booking_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->with(['timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'groomingConsent:booking_id,grooming_consent,sedation_consent,signer_name,signed_at'])
            ->first();

        if (! $booking) {
            return response()->json([
                'success' => false,
                'message' => 'Visit not found.',
            ], 404);
        }

        $booking->setRelation('timeWindow', $booking->preferredArrivalWindow());
        $booking->bookingServices->each(function ($item) {
            if ($item->service) {
                $item->service->setAttribute('kind', $item->service->kind);
            }
        });
        $booking->setAttribute('consent', $booking->groomingConsent);
        $booking->setAttribute('can_cancel', $booking->cancel_count < 2 && $booking->hasCancellableState());
        $booking->unsetRelation('groomingConsent');
        $owner = $request->user();
        $booking->setAttribute('owner', [
            'first_name' => $owner->first_name, 'last_name' => $owner->last_name,
            'phone' => $owner->phone, 'email' => $owner->email,
        ]);

        return response()->json([
            'success' => true,
            'booking' => $booking,
        ]);
    }

    // ── CANCEL A BOOKING ──────────────────────────────────
    public function cancel(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,booking_id',
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();
        app(\App\Services\PreRegistrationExpiry::class)->expire((int) $user->user_id);
        return DB::transaction(function () use ($request, $user) {
            $booking = Booking::where('booking_id', $request->booking_id)
                ->where('user_id', $user->user_id)
                ->lockForUpdate()->first();

            if (! $booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visit not found.',
                ], 404);
            }

            if (! $booking->hasCancellableState()) {
                return response()->json(['success' => false, 'message' => 'This visit cannot be cancelled.'], 422);
            }

            if ($booking->cancel_count >= 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have reached the maximum number of cancellations (2) for this visit.',
                ], 422);
            }

            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $request->reason,
                'cancelled_at' => now(), 'cancelled_by' => $user->user_id,
                'cancel_count' => $booking->cancel_count + 1,
            ]);

            $booking->bookingPets()->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)->update([
                'grooming_state' => BookingPet::GROOMING_STATE_CANCELLED, 'cancelled_at' => now(),
                'cancellation_reason' => $request->reason, 'cancelled_by' => $user->user_id,
            ]);

            Notification::create([
                'type' => 'cancelled',
                'booking_id' => $booking->booking_id,
                'message' => "Online Registration {$booking->booking_reference} was cancelled by {$user->first_name} {$user->last_name}.",
                'is_read' => 0,
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visit cancelled successfully.',
            ]);
        });
    }

    // ── RESCHEDULE A BOOKING ──────────────────────────────
    public function reschedule(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'arrival_selection_retired',
            'message' => 'Arrival selection is no longer available. Cancel an unused registration before registering again.',
        ], 410);
    }

    private function notificationScheduleLabel(string $date, ?TimeWindow $window): string
    {
        $scheduleDate = Carbon::parse($date);
        $dateLabel = $scheduleDate->year === now()->year
            ? $scheduleDate->format('M j')
            : $scheduleDate->format('M j, Y');
        $windowLabel = $window?->displayLabel();

        return $windowLabel ? "{$dateLabel} at {$windowLabel}" : $dateLabel;
    }
}
