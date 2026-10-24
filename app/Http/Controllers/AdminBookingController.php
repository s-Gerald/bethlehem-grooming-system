<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicSetting;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\Payment;
use App\Support\PetWeightSize;
use App\Notifications\GroomingFinishedNotification;
use App\Services\DailyPetQueue;
use App\Services\GroomingPaymentReadinessService;
use App\Services\WalkInCustomerRegistrationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminBookingController extends Controller
{
    public function signedConsent(int $id)
    {
        $booking = Booking::with('walkin')->findOrFail($id);
        $consent = \App\Models\GroomingConsent::where('booking_id', $id)->first();

        // Legacy false defaults cannot prove a customer declined consent.
        $legacySedation = $booking->walkin
            ? (bool) $booking->walkin->sedation_consent
            : (bool) $booking->sedation_consent;

        return response()->json([
            'consent' => $consent,
            'legacy_grooming_consent' => $booking->walkin?->terms_agreed ? true : null,
            'legacy_sedation_consent' => $legacySedation ? true : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function activeGroomingPetCount(): int
    {
        return BookingPet::where(
            'grooming_state',
            BookingPet::GROOMING_STATE_IN_PROGRESS,
        )
            ->whereHas('booking', function ($query) {
                $query->whereIn('status', ['checked_in', 'in_progress']);
            })
            ->count();
    }

    private function groomerCapacitySnapshot(): array
    {
        $groomersOnDuty = ClinicSetting::current()->groomers_on_duty;
        $activePets = $this->activeGroomingPetCount();

        return [
            'groomers_on_duty' => $groomersOnDuty,
            'active_pets' => $activePets,
            'available_slots' => max(0, $groomersOnDuty - $activePets),
            'is_full' => $activePets >= $groomersOnDuty,
        ];
    }

    private function groomerCapacityError(int $activePets, int $groomersOnDuty, int $requestedSlots = 1): array
    {
        $availableSlots = max(0, $groomersOnDuty - $activePets);
        $slotLabel = $availableSlots === 1 ? 'slot is' : 'slots are';
        $message = $availableSlots === 0
            ? "Groomer capacity is full ({$activePets} of {$groomersOnDuty} pets in progress). Finish a pet before starting another."
            : "Only {$availableSlots} groomer {$slotLabel} available, but {$requestedSlots} pets would be started.";

        return ['error' => [
            'message' => $message,
            'status' => 422,
        ]];
    }

    // ── GET BOOKINGS (split by status, filterable by date) ────────────
    public function index(Request $request)
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $today = Carbon::today();
        $selectedDate = $request->query('date', $today->toDateString());
        $includeFuture = $request->boolean('include_future', false);

        // Validate the date; fall back to today if malformed
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = $today->toDateString();
        }

        $rangeStart = Carbon::parse($selectedDate)->startOfDay();
        if ($rangeStart->lt($today)) {
            $rangeStart = $today->copy();
        }

        $maxAheadDate = $today->copy()->addDays(3);
        $rangeEnd = $includeFuture ? $maxAheadDate : $rangeStart->copy();
        if ($rangeStart->gt($rangeEnd)) {
            $rangeStart = $rangeEnd->copy();
        }

        $paymentRelations = Schema::hasTable('qr_payments') ? ['products', 'qrPayment'] : ['products'];

        // Incoming: dashboard sees only the selected day; appointments can opt in to today + future.
        $incomingQuery = Booking::whereBetween('booking_date', [
            $rangeStart->toDateString(),
            $rangeEnd->toDateString(),
        ])
            ->where('status', 'waiting_to_arrive');
        $incoming = $incomingQuery
            ->withExists('groomingConsent')
            ->with(['user', 'walkin.unregisteredCustomer', 'timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'payments' => fn ($query) => $query->where('payment_status', 'paid')->with($paymentRelations)])
            ->orderByRaw('CASE WHEN booking_date = ? THEN 0 ELSE 1 END', [$rangeStart->toDateString()])
            ->orderBy('booking_date', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        // DATE GUARD TEMPORARILY DISABLED FOR TESTING
        // Removed booking_date filter from active states so cards stay visible
        // regardless of the selected date. Restore ->where('booking_date', $selectedDate)
        // on each query below when re-enabling the date guard for production.
        $queuedQuery = Booking::where('status', 'checked_in')
            ->whereDoesntHave('bookingPets', function (Builder $pet) {
                $pet->whereNotNull('grooming_start_time');
            });
        $queued = $queuedQuery
            ->withExists('groomingConsent')
            ->with(['user', 'walkin.unregisteredCustomer', 'timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'payments' => fn ($query) => $query->where('payment_status', 'paid')->with($paymentRelations)])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        $inProgressQuery = Booking::where(function ($query) {
            $query->where('status', 'in_progress')
                ->orWhere(function ($partialBooking) {
                    $partialBooking->where('status', 'checked_in')
                        ->whereHas('bookingPets', function ($pet) {
                            $pet->whereNotNull('grooming_start_time');
                        })
                        ->whereHas('bookingPets', function ($pet) {
                            // Includes an unstarted queued sibling after a
                            // different pet in this booking has finished.
                            $pet->whereNull('grooming_end_time')->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED);
                        });
                });
        });
        $inProgress = $inProgressQuery
            ->withExists('groomingConsent')
            ->with(['user', 'walkin.unregisteredCustomer', 'timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'payments' => fn ($query) => $query->where('payment_status', 'paid')->with($paymentRelations)])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(function ($booking) {
                $formatted = $this->formatBooking($booking);
                // A partially started booking remains checked_in in storage, but
                // staff should manage the whole owner card from In Progress.
                $formatted['status'] = 'in-progress';

                return $formatted;
            });

        $forPayment = Booking::where('status', 'for_payment')
            ->withExists('groomingConsent')
            ->with(['user', 'walkin.unregisteredCustomer', 'timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'payments' => fn ($query) => $query->where('payment_status', 'paid')->with($paymentRelations)])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        $released = Booking::where('status', 'released')
            ->withExists('groomingConsent')
            ->with(['user', 'walkin.unregisteredCustomer', 'timeWindow', 'bookingPets.pet', 'bookingPets.groomer', 'bookingServices.service',
                'payments' => fn ($query) => $query->where('payment_status', 'paid')->with($paymentRelations)])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        return response()->json([
            'success' => true,
            'incomingList' => $incoming->values(),
            'queuedList' => $queued->values(),
            'inProgressList' => $inProgress->values(),
            'forPaymentList' => $forPayment->values(),
            'releasedList' => $released->values(),
            ...($request->query('view') === 'board' ? ['summary' => $this->groomingCounts()] : [
                'summary' => $this->dashboardMetrics(true, $request->user()?->role === 'admin'),
                'recentActivity' => $this->recentActivity(),
            ]),
            'activeTodayCount' => $this->activeTodayCount(),
            'capacity' => app(\App\Services\OperationalCapacity::class)->snapshot(),
            'groomerCapacity' => $this->groomerCapacitySnapshot(),
            'groomingWorkload' => app(\App\Services\GroomingWorkloadCapacity::class)->snapshot(),
            'estimateRules' => config('grooming_estimates'),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function activeTodayCount(): int
    {
        return Booking::whereDate('booking_date', Carbon::today()->toDateString())
            ->whereIn('status', ['waiting_to_arrive', 'checked_in', 'in_progress', 'for_payment', 'released'])
            ->count();
    }

    public function activityIndicator()
    {
        return response()->json(['activeTodayCount' => $this->activeTodayCount()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function dashboardSummary()
    {
        $canViewRevenue = request()->user()?->role === 'admin';
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $today = Carbon::today()->toDateString();
        $queue = Booking::whereDate('booking_date', $today)
            ->whereIn('status', ['checked_in', 'in_progress'])
            ->withExists(['bookingPets as has_started_pet' => fn ($query) => $query->whereNotNull('grooming_start_time')])
            ->with(['user:user_id,first_name,last_name', 'walkin', 'bookingPets.pet'])
            ->orderByRaw("(status = 'in_progress' OR has_started_pet) DESC")
            ->orderBy('queue_number')->limit(5)->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->booking_id,
                'status' => $booking->status === 'in_progress' || $booking->has_started_pet ? 'in-progress' : 'queued',
                'appointmentDate' => $today,
                'queueNumber' => $booking->queue_number,
                ...$this->bookingCardIdentity($booking),
                'dropOffTime' => $booking->dropped_off_at
                    ? Carbon::parse($booking->dropped_off_at)->format('g:i A')
                    : null,
            ]);

        return response()->json([
            'canViewRevenue' => $canViewRevenue,
            'summary' => $this->dashboardMetrics(false, $canViewRevenue),
            'capacity' => app(\App\Services\OperationalCapacity::class)->snapshot(),
            'queuePreview' => $queue,
            'activeTodayCount' => $this->activeTodayCount(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function dashboardActivity()
    {
        return response()->json(['recentActivity' => $this->recentActivity()])
            ->header('Cache-Control', 'no-store, private');
    }

    private function groomingCounts(bool $includeWeek = true): array
    {
        $today = Carbon::today();
        $todayCompletedCount = Booking::whereDate('grooming_finished_at', $today->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show', 'expired'])
            ->sum('number_of_pets');

        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $weekEnd = Carbon::now()->endOfWeek()->toDateString();
        $weekCount = $includeWeek ? Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
            ->whereNotIn('status', ['cancelled'])
            ->sum('number_of_pets') : null;
        return ['today' => $todayCompletedCount, ...($includeWeek ? ['week' => $weekCount] : [])];
    }

    private function dashboardMetrics(bool $includeWeek = true, bool $includeRevenue = false): array
    {
        $today = Carbon::today();
        $collections = $includeRevenue ? Payment::realCollections()->whereDate('paid_at', $today->toDateString())
            ->where('payment_status', 'paid')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total, COUNT(*) as payments')->first() : null;
        return [
                ...$this->groomingCounts($includeWeek),
                ...($includeRevenue ? ['revenueToday' => (float) $collections->total,
                    'revenuePaymentCount' => (int) $collections->payments] : []),
                'waitingNow' => app(\App\Services\OperationalCapacity::class)->waitingCount(),
        ];
    }

    // Records optional in-person consent only after staff confirms the customer agreed.
    public function recordSedationConsent(Request $request, $id)
    {
        $request->validate([
            'customer_understood_and_agreed' => ['required', 'accepted'],
        ]);

        $recordedBy = (int) $request->user()->user_id;
        $result = DB::transaction(function () use ($id, $recordedBy) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if ($booking->walkin_id !== null) {
                return ['error' => [
                    'message' => 'Use the walk-in consent record for this visit.',
                    'status' => 422,
                ]];
            }

            if ((bool) $booking->sedation_consent) {
                return ['booking' => $booking, 'already_recorded' => true];
            }

            if (! in_array($booking->status, ['waiting_to_arrive', 'checked_in'], true)) {
                return ['error' => [
                    'message' => 'Sedation consent can only be recorded before grooming starts.',
                    'status' => 422,
                ]];
            }

            $booking->update([
                'sedation_consent' => true,
                'sedation_consent_source' => 'staff_in_person',
                'sedation_consent_recorded_by' => $recordedBy,
                'sedation_consent_recorded_at' => now(),
            ]);

            return ['booking' => $booking, 'already_recorded' => false];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $booking = $result['booking'];

        return response()->json([
            'success' => true,
            'message' => $result['already_recorded']
                ? 'Sedation consent was already recorded.'
                : 'Sedation consent recorded.',
            'sedation_consent' => [
                'accepted' => true,
                'source' => $booking->sedation_consent_source,
                'recorded_at' => $booking->sedation_consent_recorded_at?->toIso8601String(),
            ],
        ]);
    }

    // ── CHECK IN ──────────────────────────────────────────
    // waiting_to_arrive → checked_in, assigns queue number
    public function checkIn($id)
    {
        $data = request()->validate([
            'pet_sizes' => ['nullable', 'array'],
            'pet_sizes.*.booking_pet_id' => ['required_with:pet_sizes', 'integer'],
            'pet_sizes.*.estimate_factors' => ['nullable', 'array'],
            'pet_sizes.*.estimate_factors.*' => ['string', 'in:'.implode(',', array_keys(config('grooming_estimates.factors')))],
            'pet_sizes.*.size' => ['required_with:pet_sizes', 'in:small,medium,large,extra_large'],
            'internal_staff_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $selectedSizes = collect($data['pet_sizes'] ?? [])->pluck('size', 'booking_pet_id');
        $queueDate = now()->toDateString();
        $result = app(DailyPetQueue::class)->runForDate($queueDate, function () use ($id, $queueDate, $selectedSizes, $data) {
            $booking = Booking::with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if ($booking->status !== 'waiting_to_arrive') {
                return ['error' => ['message' => 'Visit is not in waiting status.', 'status' => 422]];
            }

            if ($booking->booking_date !== $queueDate) {
                return ['error' => ['message' => 'Check-in is only allowed on the pre-registration date.', 'status' => 422]];
            }

            $booking->setRelation('bookingPets', $booking->bookingPets->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)->values());
            app(\App\Services\OperationalCapacity::class)->assertCanAccept('grooming', $booking->bookingPets->count());

            $bookingPetIds = $booking->bookingPets->pluck('booking_pet_id')->map(fn ($id) => (int) $id)->all();
            if ($selectedSizes->isNotEmpty() &&
                (count($bookingPetIds) !== $selectedSizes->count()
                    || array_diff($bookingPetIds, $selectedSizes->keys()->map(fn ($id) => (int) $id)->all()))) {
                return ['error' => ['message' => 'Confirm a size for every pet in this visit.', 'status' => 422]];
            }

            foreach ($booking->bookingPets as $bookingPet) {
                $pet = $bookingPet->pet;
                if (! $pet) {
                    return ['error' => ['message' => 'A pet in this visit could not be found.', 'status' => 422]];
                }
                $size = $selectedSizes->get($bookingPet->booking_pet_id) ?? $pet->groomingSize();
                if (! PetWeightSize::isValidSize($pet->species, $size)) {
                    return ['error' => ['message' => "Select a valid size for {$pet->pet_name}.", 'status' => 422]];
                }
            }

            foreach ($booking->bookingPets as $bookingPet) {
                $pet = $bookingPet->pet;
                $size = $selectedSizes->get($bookingPet->booking_pet_id) ?? $pet->groomingSize();
                $bookingPet->confirmed_size = $size;
                $bookingPet->save();
                $bookingPet->setRelation('booking', $booking);
                $assessment = collect($data['pet_sizes'] ?? [])->firstWhere('booking_pet_id', $bookingPet->booking_pet_id);
                $factors = $assessment['estimate_factors'] ?? $bookingPet->grooming_estimate_factors ?? [];
                app(\App\Services\GroomingTimeEstimate::class)->recalculate($booking, $bookingPet, $factors);
                $pet->confirmClinicSize($size);
            }

            $workload = app(\App\Services\GroomingWorkloadCapacity::class);
            $workload->assertCanAdmit($workload->admissionJobs($booking->bookingPets), excludeBookingId: (int) $booking->booking_id);

            $queueNumber = ((int) Booking::where('booking_date', $queueDate)
                ->whereNotNull('queue_number')
                ->max('queue_number')) + 1;

            $bookingUpdates = [
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'dropped_off_at' => now(),
            ];
            if (array_key_exists('internal_staff_note', $data)) {
                $bookingUpdates['internal_staff_note'] = trim($data['internal_staff_note'] ?? '') ?: null;
            }
            $booking->update($bookingUpdates);

            app(DailyPetQueue::class)->assignBookingPets($booking, $queueDate);

            return ['queue_number' => $queueNumber];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer checked in successfully.',
            'queue_number' => $result['queue_number'],
        ]);
    }

    public function updateInternalStaffNote($id)
    {
        $data = request()->validate(['internal_staff_note' => ['present', 'nullable', 'string', 'max:5000']]);
        return DB::transaction(function () use ($id, $data) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($booking->walkin_id !== null || ! in_array($booking->status, ['waiting_to_arrive', 'checked_in', 'in_progress'], true)) {
                return response()->json(['message' => 'Internal Staff Note is read-only for this visit.'], 422);
            }

            $booking->update(['internal_staff_note' => trim($data['internal_staff_note'] ?? '') ?: null]);

            return response()->json(['internal_staff_note' => $booking->internal_staff_note]);
        });
    }

    public function updateGroomingVisitNotes($id, $bookingPetId)
    {
        $data = request()->validate(['grooming_visit_notes' => ['present', 'nullable', 'string', 'max:1000']]);
        return DB::transaction(function () use ($id, $bookingPetId, $data) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($booking->walkin_id === null || ! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                return response()->json(['message' => 'Grooming & Visit Notes are read-only for this visit.'], 422);
            }

            $bookingPet = BookingPet::where('booking_id', $booking->booking_id)->findOrFail($bookingPetId);
            $bookingPet->update(['special_instructions' => trim($data['grooming_visit_notes'] ?? '') ?: null]);

            return response()->json(['grooming_visit_notes' => $bookingPet->special_instructions]);
        });
    }

    // Undo check-in completely: restore the pre-arrival booking and release all queue fields.
    public function revertCheckIn($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            $this->assertUnpaidCorrection($booking);

            if ($booking->status !== 'checked_in') {
                return ['error' => [
                    'message' => 'Only a queued visit can be reverted to Incoming.',
                    'status' => 422,
                ]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $hasGroomingActivity = $bookingPets->contains(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_start_time !== null
                    || $bookingPet->grooming_end_time !== null
                    || in_array($bookingPet->grooming_state, [
                        BookingPet::GROOMING_STATE_IN_PROGRESS,
                        BookingPet::GROOMING_STATE_FINISHED,
                    ], true),
            );

            if ($hasGroomingActivity || $booking->grooming_started_at || $booking->grooming_finished_at
                || $bookingPets->contains(fn ($pet) => ! in_array($pet->grooming_state, [
                    BookingPet::GROOMING_STATE_NOT_STARTED, BookingPet::GROOMING_STATE_CANCELLED,
                ], true))) {
                return ['error' => [
                    'message' => 'Check-in cannot be reverted after grooming activity has started.',
                    'status' => 422,
                ]];
            }

            $booking->update([
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ]);

            BookingPet::whereIn('booking_pet_id', $bookingPets->pluck('booking_pet_id'))
                ->update([
                    'pet_queue_date' => null,
                    'pet_queue_number' => null,
                ]);

            return ['booking_id' => $booking->booking_id];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Check-in undone. The visit is Incoming again.',
            'booking_id' => $result['booking_id'],
            'status' => 'waiting_to_arrive',
        ]);
    }

    // ── START GROOMING ────────────────────────────────────
    // checked_in → in_progress
    public function startGrooming($id)
    {
        $result = DB::transaction(function () use ($id) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if ($booking->status !== 'checked_in') {
                return ['error' => ['message' => 'Visit must be checked in first.', 'status' => 422]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $petsToStart = $bookingPets->filter(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_state === BookingPet::GROOMING_STATE_NOT_STARTED
                    && $bookingPet->grooming_start_time === null
                    && $bookingPet->grooming_end_time === null,
            );

            if ($petsToStart->isEmpty()) {
                return ['error' => [
                    'message' => 'No eligible not-started pets are available to begin grooming.',
                    'status' => 422,
                ]];
            }

            $activePets = $this->activeGroomingPetCount();

            if ($activePets + $petsToStart->count() > $settings->groomers_on_duty) {
                return $this->groomerCapacityError(
                    $activePets,
                    $settings->groomers_on_duty,
                    $petsToStart->count(),
                );
            }

            $startedAt = now();
            if ($petsToStart->isNotEmpty()) {
                $booking->loadMissing('bookingServices.service');
                $petsToStart->loadMissing('pet');
                foreach ($petsToStart as $petToStart) {
                    if (! $petToStart->grooming_estimate_min) app(\App\Services\GroomingTimeEstimate::class)->recalculate($booking, $petToStart);
                }
                BookingPet::whereIn('booking_pet_id', $petsToStart->pluck('booking_pet_id'))
                    ->update([
                        'grooming_start_time' => $startedAt,
                        'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
                    ]);
            }

            $booking->update([
                'status' => 'in_progress',
                'grooming_started_at' => $booking->grooming_started_at ?? $startedAt,
            ]);
            $booking->load('user', 'bookingPets.pet');

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $booking = $result['booking'];

        // Notify the customer that grooming has started
        if ($booking->user) {
            $petName = $this->petNames($booking);
            $petVerb = $this->hasMultiplePets($booking) ? 'have' : 'has';
            CustomerNotification::create([
                'user_id' => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type' => 'grooming_started',
                'message' => "Great news! {$petName} {$petVerb} Started Grooming. We'll let you know as soon as they're ready for pickup!",
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grooming session started.',
        ]);
    }

    // Starts one pet while the owner booking remains checked_in until every pet has started.
    // The schedule feed surfaces partially started owner cards in In Progress.
    // No migration is needed: booking_pets.grooming_start_time already stores this per-pet state.
    public function startPetGrooming($id, $bookingPetId)
    {
        $assessment = request()->validate([
            'grooming_preference' => ['nullable', 'string', 'max:32'],
            'estimate_factors' => ['sometimes', 'array'],
            'estimate_factors.*' => ['string', 'in:'.implode(',', array_keys(config('grooming_estimates.factors')))],
        ]);
        $result = DB::transaction(function () use ($id, $bookingPetId, $assessment) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if ($booking->status !== 'checked_in') {
                return ['error' => ['message' => 'Visit must be queued before a pet can start grooming.', 'status' => 422]];
            }

            $bookingPet = BookingPet::where('booking_id', $booking->booking_id)
                ->whereKey($bookingPetId)
                ->lockForUpdate()
                ->first();

            if (! $bookingPet) {
                return ['error' => ['message' => 'Pet is not part of this visit.', 'status' => 404]];
            }

            if (
                $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_NOT_STARTED
                || $bookingPet->grooming_start_time !== null
                || $bookingPet->grooming_end_time !== null
            ) {
                return ['error' => [
                    'message' => 'Only a not-started pet can begin grooming.',
                    'status' => 422,
                ]];
            }

            $activePets = $this->activeGroomingPetCount();
            if ($activePets >= $settings->groomers_on_duty) {
                return $this->groomerCapacityError($activePets, $settings->groomers_on_duty);
            }

            if ($booking->booking_type === 'walk_in' || $booking->walkin_id !== null) {
                if ($bookingPet->grooming_estimate_min <= 0
                    || $bookingPet->grooming_estimate_max < $bookingPet->grooming_estimate_min) {
                    // Legacy walk-ins may lack a duration. Preserve their saved assessment.
                    $estimate = app(\App\Services\GroomingTimeEstimate::class)->estimateForPet($booking, $bookingPet);
                    if ($estimate) {
                        $bookingPet->fill([
                            'grooming_estimate_min' => $estimate['minMinutes'],
                            'grooming_estimate_max' => $estimate['maxMinutes'],
                        ])->save();
                    }
                }
            } elseif (! $bookingPet->grooming_estimate_min
                || ($assessment['estimate_factors'] ?? $bookingPet->grooming_estimate_factors ?? []) !== ($bookingPet->grooming_estimate_factors ?? [])
                || ($assessment['grooming_preference'] ?? $bookingPet->grooming_preference) !== $bookingPet->grooming_preference) {
                app(\App\Services\GroomingTimeEstimate::class)->recalculate($booking, $bookingPet,
                    $assessment['estimate_factors'] ?? null, $assessment['grooming_preference'] ?? null);
            }
            $startedAt = now();
            $bookingPet->update([
                'grooming_start_time' => $startedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ]);

            $remainingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)
                ->count();

            $bookingUpdates = [];
            if (! $booking->grooming_started_at) {
                $bookingUpdates['grooming_started_at'] = $startedAt;
            }
            if ($remainingPets === 0) {
                $bookingUpdates['status'] = 'in_progress';
            }
            if ($bookingUpdates) {
                $booking->update($bookingUpdates);
            }

            $booking->loadMissing(['user', 'bookingPets.pet']);
            $petName = $bookingPet->pet()->value('pet_name') ?: 'your pet';

            if ($booking->user) {
                CustomerNotification::create([
                    'user_id' => $booking->user->user_id,
                    'booking_id' => $booking->booking_id,
                    'type' => 'grooming_started',
                    'message' => "Great news! {$petName} has Started Grooming. We'll let you know as soon as they're ready for pickup!",
                    'is_read' => false,
                    'created_at' => $startedAt,
                ]);
            }

            return [
                'booking' => $booking,
                'bookingPet' => $bookingPet,
                'petName' => $petName,
                'allPetsStarted' => $remainingPets === 0,
                'remainingPets' => $remainingPets,
                'startedAt' => $startedAt,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => "Grooming started for {$result['petName']}.",
            'booking_id' => $result['booking']->booking_id,
            'booking_pet_id' => $result['bookingPet']->booking_pet_id,
            'started_at' => $result['startedAt']->toIso8601String(),
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'all_pets_started' => $result['allPetsStarted'],
            'remaining_pets' => $result['remainingPets'],
            'booking_status' => $result['allPetsStarted'] ? 'in_progress' : 'checked_in',
        ]);
    }

    // Retain the legacy route for single-pet API consumers only.
    public function revertStartGrooming($id)
    {
        return DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertUnpaidCorrection($booking);
            $pets = $booking->bookingPets()->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)->lockForUpdate()->get();
            if ($pets->count() !== 1) {
                throw ValidationException::withMessages(['booking' => 'Use Undo Start for a specific pet.']);
            }
            return $this->undoPetStart($id, $pets->first()->booking_pet_id);
        });
    }

    public function undoPetStart($id, $bookingPetId)
    {
        return DB::transaction(function () use ($id, $bookingPetId) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertUnpaidCorrection($booking);
            if (! in_array($booking->status, ['checked_in', 'in_progress'], true) || $booking->grooming_finished_at) {
                throw ValidationException::withMessages(['booking' => 'This visit cannot undo grooming.']);
            }
            $pets = $booking->bookingPets()->lockForUpdate()->get();
            $pet = $pets->firstWhere('booking_pet_id', $bookingPetId);
            abort_unless($pet, 404, 'Pet is not part of this visit.');
            if (! $pet->canUndoGroomingStart()) {
                throw ValidationException::withMessages(['pet' => 'Only an in-progress pet can undo its start.']);
            }
            $pet->update(['grooming_start_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED]);
            $booking->update(['status' => 'checked_in',
                'grooming_started_at' => $pets->pluck('grooming_start_time')->filter()->sort()->first()]);
            return response()->json(['success' => true, 'message' => 'Grooming start undone.',
                'booking_id' => $booking->booking_id, 'booking_pet_id' => $pet->booking_pet_id,
                'status' => 'checked_in',
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED]);
        });
    }

    private function assertUnpaidCorrection(Booking $booking): void
    {
        \App\Models\GatewayPayment::assertGroomingUnlocked($booking);
        if ($booking->hasSuccessfulPayment()) {
            throw ValidationException::withMessages(['booking' => 'Paid grooming visits cannot be cancelled or undone.']);
        }
    }

    public function markDone($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if ($booking->status !== 'in_progress') {
                return ['error' => ['message' => 'Visit must be in progress first.', 'status' => 422]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $unfinishedPets = $bookingPets->whereNotIn('grooming_state', [
                BookingPet::GROOMING_STATE_FINISHED, BookingPet::GROOMING_STATE_CANCELLED,
            ]);

            if ($unfinishedPets->isEmpty()) {
                return ['error' => [
                    'message' => 'Every pet in this visit is already marked as finished.',
                    'status' => 422,
                ]];
            }

            $petsToFinish = $unfinishedPets->filter(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_state
                    === BookingPet::GROOMING_STATE_IN_PROGRESS,
            );
            $hasIneligiblePet = $unfinishedPets->contains(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_state
                    !== BookingPet::GROOMING_STATE_IN_PROGRESS
                    || $bookingPet->grooming_start_time === null
                    || $bookingPet->grooming_end_time !== null,
            );

            if ($hasIneligiblePet) {
                return ['error' => [
                    'message' => 'All unfinished pets must be in progress before the visit can be completed.',
                    'status' => 422,
                ]];
            }

            if ($petsToFinish->isEmpty()) {
                return ['error' => [
                    'message' => 'There are no in-progress pets to finish normally.',
                    'status' => 422,
                ]];
            }

            $finishedAt = now();
            BookingPet::whereIn(
                'booking_pet_id',
                $petsToFinish->pluck('booking_pet_id'),
            )->update([
                'grooming_end_time' => $finishedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ]);

            return [
                'booking' => $booking,
                'petNames' => $petsToFinish
                    ->map(fn (BookingPet $bookingPet) => $bookingPet->pet()->value('pet_name') ?: 'Pet')
                    ->values()
                    ->all(),
                'completion' => $this->completeGroomingBooking(
                    $booking,
                    $finishedAt,
                ),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $this->notifyOwnerGroomingFinished($result['booking'], $result['petNames']);

        return response()->json([
            'success' => true,
            'message' => $result['completion']['message'],
            'booking_status' => $result['completion']['status'],
        ]);
    }

    // ── MARK ONE PET DONE ─────────────────────────────────
    // Finishes one pet and keeps the owner booking In Progress until every pet is done.
    // booking_pets.grooming_end_time already provides the required per-pet state.
    public function markPetDone($id, $bookingPetId)
    {
        $result = DB::transaction(function () use ($id, $bookingPetId) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }

            if (! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                return ['error' => ['message' => 'Visit must be queued or in progress first.', 'status' => 422]];
            }

            $bookingPet = BookingPet::where('booking_id', $booking->booking_id)
                ->whereKey($bookingPetId)
                ->lockForUpdate()
                ->first();

            if (! $bookingPet) {
                return ['error' => ['message' => 'Pet is not part of this visit.', 'status' => 404]];
            }

            if (
                $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_IN_PROGRESS
                || $bookingPet->grooming_start_time === null
                || $bookingPet->grooming_end_time !== null
            ) {
                return ['error' => [
                    'message' => 'Only a pet currently in progress can be finished.',
                    'status' => 422,
                ]];
            }

            $finishedAt = now();
            $bookingPet->update([
                'grooming_end_time' => $finishedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ]);

            $remainingPets = BookingPet::query()
                ->where('booking_id', $booking->booking_id)
                ->whereNotIn('grooming_state', [BookingPet::GROOMING_STATE_FINISHED, BookingPet::GROOMING_STATE_CANCELLED])
                ->count();
            $allPetsFinished = $remainingPets === 0;
            $petName = $bookingPet->pet()->value('pet_name') ?: 'Pet';
            $completion = null;

            if ($allPetsFinished) {
                $completion = $this->completeGroomingBooking($booking, $finishedAt);
            } else {
                $booking->loadMissing('user');

                if ($booking->user) {
                    CustomerNotification::create([
                        'user_id' => $booking->user->user_id,
                        'booking_id' => $booking->booking_id,
                        'type' => 'grooming_finished',
                        'message' => "{$petName} is Finished with grooming. We'll keep you updated on the rest of the visit.",
                        'is_read' => false,
                        'created_at' => $finishedAt,
                    ]);
                }
            }

            return [
                'booking' => $booking,
                'bookingPet' => $bookingPet,
                'petName' => $petName,
                'allPetsFinished' => $allPetsFinished,
                'remainingPets' => $remainingPets,
                'finishedAt' => $finishedAt,
                'completion' => $completion,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $this->notifyOwnerGroomingFinished(
            $result['booking'],
            [$result['petName']],
        );

        $allPetsFinished = $result['allPetsFinished'];
        $remainingPets = $result['remainingPets'];
        $remainingLabel = $remainingPets === 1 ? 'pet remains' : 'pets remain';

        return response()->json([
            'success' => true,
            'message' => $allPetsFinished
                ? $result['completion']['message']
                : "Grooming finished for {$result['petName']}. {$remainingPets} {$remainingLabel} in progress.",
            'booking_id' => $result['booking']->booking_id,
            'booking_pet_id' => $result['bookingPet']->booking_pet_id,
            'finished_at' => $result['finishedAt']->toIso8601String(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'all_pets_finished' => $allPetsFinished,
            'remaining_pets' => $remainingPets,
            'booking_status' => $allPetsFinished
                ? $result['completion']['status']
                : $result['booking']->status,
        ]);
    }

    private function completeGroomingBooking(Booking $booking, Carbon $finishedAt): array
    {
        $booking->loadMissing(['user', 'bookingPets.pet']);
        $ownerName = trim(($booking->user?->first_name ?? '').' '.($booking->user?->last_name ?? ''));
        $readySubject = $this->hasMultiplePets($booking) ? 'pets are' : 'pet is';

        if ($booking->user) {
            CustomerNotification::create([
                'user_id' => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type' => 'ready_for_pickup',
                'message' => "Your {$readySubject} now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.",
                'is_read' => false,
                'created_at' => $finishedAt,
            ]);
        }

        if ($booking->hasSuccessfulPayment()) {
            $booking->update([
                'status' => 'released',
                'grooming_finished_at' => $finishedAt,
            ]);

            return [
                'status' => 'released',
                'message' => 'Grooming done. Customer notified for pickup (early payment on file).',
            ];
        }

        $booking->update([
            'status' => 'for_payment',
            'grooming_finished_at' => $finishedAt,
        ]);

        Notification::create([
            'type' => 'payment_due',
            'booking_id' => $booking->booking_id,
            'message' => "Grooming done for {$ownerName}. Pet is ready - please collect payment.",
            'is_read' => false,
            'created_at' => $finishedAt,
        ]);

        return [
            'status' => 'for_payment',
            'message' => 'Grooming done. Customer notified for pickup and payment.',
        ];
    }

    /**
     * Queue the separate grooming-completion email without changing the
     * customer's existing dashboard notification.
     *
     * @param  array<int, string>  $petNames
     */
    private function notifyOwnerGroomingFinished(Booking $booking, array $petNames): void
    {
        $booking->loadMissing('user');

        if (! $booking->user || ! filled($booking->user->email)) {
            return;
        }

        $booking->user->notify(new GroomingFinishedNotification($petNames));
    }

    // ── MARK PICKED UP ────────────────────────────────────
    // released → archived + customer notification
    public function markPickedUp(Request $request, $id)
    {
        $createdByUserId = $request->user()?->user_id;

        $result = DB::transaction(function () use ($id, $createdByUserId) {
            $booking = Booking::query()->whereKey($id)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }
            if ($booking->status !== 'released') {
                return ['error' => ['message' => 'Visit must be in Released status.', 'status' => 422]];
            }
            if (! (bool) $booking->paid) {
                return ['error' => ['message' => 'Payment must be completed before physical pickup.', 'status' => 422]];
            }

            app(WalkInCustomerRegistrationService::class)
                ->registerNewOwnerAtPickup($booking, $createdByUserId);
            $booking->unsetRelation('user');

            $booking->loadMissing(['user', 'bookingPets.pet']);
            $petName = $this->petNames($booking);
            $petVerb = $this->hasMultiplePets($booking) ? 'have' : 'has';
            $booking->update(['status' => 'archived', 'archived_at' => now()]);

            if ($booking->user) {
                CustomerNotification::create([
                    'user_id' => $booking->user->user_id,
                    'booking_id' => $booking->booking_id,
                    'type' => 'picked_up',
                    'message' => "{$petName} {$petVerb} been released. Thank you for visiting Bethlehem Animal Clinic!",
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visit marked as picked up and archived.',
        ]);
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // for_pickup (legacy) → archived
    // Cancel a booking, remove it from active capacity, and notify its customer.
    public function cancel(Request $request, $id)
    {
        $reason = $this->cancellationReason($request);
        return DB::transaction(function () use ($request, $id, $reason) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertUnpaidCorrection($booking);
            $pets = $booking->bookingPets()->lockForUpdate()->get();
            if (! in_array($booking->status, ['waiting_to_arrive', 'incoming', 'checked_in'], true)
                || $booking->grooming_started_at || $booking->grooming_finished_at
                || $pets->contains(fn ($pet) => $pet->grooming_state !== BookingPet::GROOMING_STATE_CANCELLED && ! $pet->canCancelGrooming())) {
                throw ValidationException::withMessages(['booking' => 'Cancellation is only available before any grooming starts.']);
            }
            $audit = ['cancelled_at' => now(), 'cancellation_reason' => $reason, 'cancelled_by' => $request->user()?->user_id];
            foreach ($pets as $pet) {
                if ($pet->grooming_state !== BookingPet::GROOMING_STATE_CANCELLED) {
                    $pet->update($audit + ['grooming_state' => BookingPet::GROOMING_STATE_CANCELLED]);
                }
            }
            $this->cancelGroomingBooking($booking, $audit);
            $notified = $this->notifyCancellation($booking, $reason);
            return response()->json(['success' => true, 'message' => 'Visit cancelled successfully.', 'customer_notified' => $notified]);
        });
    }

    public function cancelPet(Request $request, $id, $bookingPetId)
    {
        $reason = $this->cancellationReason($request);
        return DB::transaction(function () use ($request, $id, $bookingPetId, $reason) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertUnpaidCorrection($booking);
            if (! in_array($booking->status, ['waiting_to_arrive', 'incoming', 'checked_in', 'in_progress'], true) || $booking->grooming_finished_at) {
                throw ValidationException::withMessages(['booking' => 'This visit cannot cancel pets.']);
            }
            $pets = $booking->bookingPets()->with('pet')->lockForUpdate()->get();
            $pet = $pets->firstWhere('booking_pet_id', $bookingPetId);
            abort_unless($pet, 404, 'Pet is not part of this visit.');
            if (! $pet->canCancelGrooming()) {
                throw ValidationException::withMessages(['pet' => 'Only a not-started pet can be cancelled.']);
            }
            $audit = ['cancelled_at' => now(), 'cancellation_reason' => $reason, 'cancelled_by' => $request->user()?->user_id];
            $pet->update($audit + ['grooming_state' => BookingPet::GROOMING_STATE_CANCELLED]);
            $remaining = $pets->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED);
            if ($remaining->isEmpty()) {
                $this->cancelGroomingBooking($booking, $audit);
            } elseif ($remaining->every(fn ($pet) => $pet->grooming_state === BookingPet::GROOMING_STATE_FINISHED && $pet->grooming_end_time !== null)) {
                $this->completeGroomingBooking($booking, $remaining->max('grooming_end_time'));
            } elseif ($remaining->every(fn ($pet) => $pet->grooming_state !== BookingPet::GROOMING_STATE_NOT_STARTED)) {
                $booking->update(['status' => 'in_progress']);
            }
            $notified = $this->notifyCancellation($booking, $reason, $pet, $remaining->isNotEmpty());
            return response()->json(['success' => true, 'message' => 'Pet grooming cancelled.',
                'customer_notified' => $notified, 'booking_status' => $booking->status]);
        });
    }

    private function cancellationReason(Request $request): string
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:500']]);
        $reason = trim($data['cancellation_reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_reason' => 'Enter a cancellation reason.']);
        }
        return $reason;
    }

    private function cancelGroomingBooking(Booking $booking, array $audit): void
    {
        $booking->update($audit + ['status' => 'cancelled', 'cancel_count' => ($booking->cancel_count ?? 0) + 1]);
        Notification::create(['type' => 'cancelled', 'booking_id' => $booking->booking_id,
            'message' => "Grooming visit {$booking->booking_reference} was cancelled by clinic staff.",
            'is_read' => false, 'created_at' => now()]);
    }

    private function notifyCancellation(Booking $booking, string $reason, ?BookingPet $pet = null, bool $othersActive = false): bool
    {
        $booking->loadMissing('user');
        if (! $booking->user) return false;
        $subject = $booking->walkin_id === null ? 'grooming pre-registration' : 'grooming visit';
        $message = $pet
            ? ($pet->pet?->pet_name ?? 'Your pet')."'s grooming was cancelled. Reason: {$reason}"
            : "Your {$subject} {$booking->booking_reference} has been cancelled by the clinic. Reason: {$reason}";
        if ($pet) $message .= $othersActive ? ' Other pets remain active.' : ' The visit is now cancelled.';
        CustomerNotification::create(['user_id' => $booking->user_id, 'booking_id' => $booking->booking_id,
            'type' => CustomerNotification::TYPE_BOOKING_CANCELLED, 'message' => $message, 'is_read' => false, 'created_at' => now()]);
        return true;
    }

    public function archive($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::query()->whereKey($id)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => ['message' => 'Visit not found.', 'status' => 404]];
            }
            if (! in_array($booking->status, ['for_pickup', 'released'], true)) {
                return ['error' => [
                    'message' => 'Only For Pickup or Released visits can be archived here.',
                    'status' => 422,
                ]];
            }
            if (! (bool) $booking->paid) {
                return ['error' => [
                    'message' => 'Payment must be completed before the visit can be archived.',
                    'status' => 422,
                ]];
            }

            $booking->update(['status' => 'archived', 'archived_at' => now()]);

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visit archived successfully.',
        ]);
    }

    // ── GET ARCHIVED BOOKINGS ────────────────────────────
    public function archivedIndex(Request $request)
    {
        $search = $request->query('search', '');
        $date = $request->query('date', '');

        $paymentRelations = Schema::hasTable('qr_payments') ? ['products', 'qrPayment'] : ['products'];
        $query = Booking::whereIn('status', ['archived', 'cancelled'])
            ->withExists('groomingConsent')
            ->with([
                'user',
                'walkin.unregisteredCustomer',
                'timeWindow',
                'bookingPets.pet', 'bookingPets.groomer',
                'bookingPets.cancelledBy',
                'cancelledBy',
                'bookingServices.service',
                'payments' => fn ($q) => $q->with($paymentRelations)
                    ->where('payment_status', 'paid')
                    ->orderBy('paid_at', 'desc'),
            ])
            ->orderBy('archived_at', 'desc');

        if ($date) {
            $query->where('booking_date', $date);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q2) use ($search) {
                        $q2->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('bookingPets.pet', function ($q2) use ($search) {
                        $q2->where('pet_name', 'like', "%{$search}%");
                    });
            });
        }

        $archived = $query->get()->map(fn ($b) => $this->formatArchivedBooking($b));

        return response()->json([
            'success' => true,
            'archived' => $archived->values(),
            'total' => $archived->count(),
        ]);
    }

    // ── OWNER CARD CONCERN STATE ──────────────────────────
    // ── FORMAT BOOKING FOR FRONTEND ───────────────────────
    private function bookingCardIdentity(Booking $booking): array
    {
        $pets = $booking->bookingPets->sortBy('booking_pet_id')->values();
        $firstPet = $pets->first()?->pet;
        $petName = $firstPet?->pet_name ?? '—';
        if ($pets->count() > 1) $petName .= ' +'.($pets->count() - 1).' more';

        return [
            'petName' => $petName,
            'breed' => $firstPet?->breed ?? '—',
            'ownerName' => $booking->user || $booking->walkin ? $this->ownerName($booking) : '—',
        ];
    }

    private function formatBooking(Booking $booking, ?array $paymentSummary = null): array
    {
        $user = $booking->user;
        $walkin = $booking->walkin;
        $window = $booking->preferredArrivalWindow();
        $bpets = ($booking->bookingPets ?? collect())
            ->sortBy('booking_pet_id')
            ->values();
        $firstBp = $bpets->first();
        $firstPet = $firstBp?->pet;
        $bpetsById = $bpets->keyBy('booking_pet_id');

        $petType = $this->formatPetTypeSummary($bpets);

        // Build service label from booked services
        $bookedServices = $booking->bookingServices ?? collect();
        $serviceNames = $bookedServices
            ->map(fn ($bs) => $bs->service?->service_name)
            ->filter()
            ->unique()
            ->values();

        $serviceLabel = $serviceNames->isNotEmpty()
            ? $serviceNames->implode(', ')
            : 'Grooming';

        $paidPayment = $this->paidPaymentForBooking($booking);
        $paidTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : ((bool) $booking->paid && (float) $booking->total_amount > 0
                ? (float) $booking->total_amount
                : null);
        $productLines = $paidPayment?->products ?? collect();
        $paidServicesTotal = $paidTotal !== null
            ? round($paidTotal - (float) $productLines->sum('subtotal'), 2)
            : null;
        $billableServices = $bookedServices->filter(fn ($service) => $bpetsById->get($service->booking_pet_id)?->grooming_state !== BookingPet::GROOMING_STATE_CANCELLED);
        $bookedServicesTotal = round((float) $billableServices->sum(
            fn ($bs) => (float) ($bs->price_at_booking ?? 0),
        ), 2);
        $canUseSavedServicePrices = ! $paidTotal
            || ($bookedServicesTotal > 0 && abs($bookedServicesTotal - $paidServicesTotal) <= 0.01);
        $paymentSummary ??= $this->paymentReadiness()->summarize($booking);
        $paymentPetsById = collect($paymentSummary['pets'])->keyBy('booking_pet_id');
        $ownerAccountDeleted = $this->ownerAccountDeleted($booking);

        return [
            // Fields the card templates read directly
            'id' => $booking->booking_id,
            'queueNumber' => $booking->queue_number ?? 0,
            ...$this->bookingCardIdentity($booking),
            'contactNumber' => $ownerAccountDeleted
                ? '—'
                : ($user?->phone ?? $walkin?->phone ?? '—'),
            'ownerAccountDeleted' => $ownerAccountDeleted,
            'owner_account_deleted' => $ownerAccountDeleted,
            'sedationConsentRecorded' => (bool) $booking->grooming_consent_exists
                || ($walkin ? (bool) $walkin->sedation_consent : (bool) $booking->sedation_consent),
            'sedationConsent' => $walkin
                ? (bool) $walkin->sedation_consent
                : (bool) $booking->sedation_consent,
            'sedation_consent' => $walkin
                ? (bool) $walkin->sedation_consent
                : (bool) $booking->sedation_consent,
            'sedationConsentSource' => $walkin
                ? ((bool) $walkin->sedation_consent ? 'staff_walk_in' : null)
                : $booking->sedation_consent_source,
            'sedation_consent_source' => $walkin
                ? ((bool) $walkin->sedation_consent ? 'staff_walk_in' : null)
                : $booking->sedation_consent_source,
            'sedationConsentRecordedAt' => $walkin
                ? ($walkin->sedation_consent ? $walkin->created_at?->toIso8601String() : null)
                : $booking->sedation_consent_recorded_at?->toIso8601String(),
            'canRecordSedationConsent' => ! $walkin
                && ! (bool) $booking->sedation_consent
                && in_array($booking->status, ['waiting_to_arrive', 'checked_in'], true),
            'petType' => $petType,
            'petSize' => $firstBp?->confirmed_size ?? $firstPet?->groomingSize(),
            'size' => $firstBp?->confirmed_size ?? $firstPet?->groomingSize(),
            'serviceLabel' => $serviceLabel,
            'appointmentDate' => $booking->booking_date,
            'appointmentTime' => $window?->window_label ?? '—',
            'dropOffTime' => $booking->dropped_off_at
                ? Carbon::parse($booking->dropped_off_at)->format('g:i A')
                : null,
            'startedAt' => $booking->grooming_started_at
                ? Carbon::parse($booking->grooming_started_at)->format('g:i A')
                : null,
            'completedAt' => $booking->grooming_finished_at
                ? Carbon::parse($booking->grooming_finished_at)->format('g:i A')
                : null,
            'clientNotified' => false,
            'paid' => (bool) $booking->paid || $paidPayment !== null,
            'cancellationReason' => $booking->cancellation_reason,
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'cancelledBy' => $booking->relationLoaded('cancelledBy') && $booking->cancelledBy
                ? trim($booking->cancelledBy->first_name.' '.$booking->cancelledBy->last_name) : null,
            'status' => $booking->status === 'cancelled' || $booking->cancel_count > 0 || trim((string) $booking->cancellation_reason) !== ''
                ? 'cancelled' : $this->mapStatus($booking->status),
            // Extra fields for the View Details modal
            'bookingReference' => $booking->booking_reference,
            'bookingType' => $booking->walkin_id !== null ? 'Walk-In' : 'Pre-Register',
            'specialNotes' => $booking->special_notes,
            'internalStaffNote' => $booking->walkin_id === null ? $booking->internal_staff_note : null,
            'numberOfPets' => $booking->number_of_pets,
            'paidAmount' => $paidTotal,
            'paid_amount' => $paidTotal,
            'paymentReady' => $paymentSummary['payment_ready'],
            'payment_ready' => $paymentSummary['payment_ready'],
            'paymentBlockedReason' => $paymentSummary['payment_blocked_reason'],
            'payment_blocked_reason' => $paymentSummary['payment_blocked_reason'],
            'finalPaymentTotal' => $paymentSummary['final_booking_total'],
            'final_payment_total' => $paymentSummary['final_booking_total'],
            'paymentSummary' => $paymentSummary,
            'payment_summary' => $paymentSummary,
            'payment' => $paidPayment ? [
                'id' => $paidPayment->payment_id ?? $paidPayment->id ?? null,
                'finalPrice' => (float) $paidPayment->total_amount,
                'final_price' => (float) $paidPayment->total_amount,
                'amountPaid' => (float) $paidPayment->amount_tendered,
                'amount_paid' => (float) $paidPayment->amount_tendered,
                'paymentMethod' => $paidPayment->payment_method,
                'paymentMethodLabel' => $paidPayment->methodLabel(),
                'payment_method' => $paidPayment->payment_method,
                'paidAt' => $paidPayment->paid_at
                    ? Carbon::parse($paidPayment->paid_at)->format('M j, Y g:i A')
                    : null,
                'paid_at' => $paidPayment->paid_at,
            ] : null,
            'productAddons' => $productLines->map(fn ($line) => [
                'itemName' => $line->item_name,
                'quantity' => $line->quantity,
                'priceAtSale' => (float) $line->price_at_sale,
                'subtotal' => (float) $line->subtotal,
            ])->values(),
            'pets' => $bpets->values()->map(function ($bp, $petIndex) use ($paymentPetsById) {
                $pet = $bp->pet;
                $groomingState = $this->bookingPetGroomingState($bp);
                $paymentPet = $paymentPetsById->get($bp->booking_pet_id, []);
                return [
                    'id' => $bp->booking_pet_id,
                    'bookingPetId' => $bp->booking_pet_id,
                    'booking_pet_id' => $bp->booking_pet_id,
                    'petId' => $pet?->pet_id,
                    'pet_id' => $pet?->pet_id,
                    'pet_name' => $pet?->pet_name,
                    'name' => $pet?->pet_name,
                    'petType' => ucfirst($pet?->species ?? 'Dog'),
                    'pet_type' => $pet?->species,
                    'petSize' => $bp->confirmed_size ?? $pet?->groomingSize(),
                    'pet_size' => $bp->confirmed_size ?? $pet?->groomingSize(),
                    'petName' => $pet?->pet_name ?? '—',
                    'species' => ucfirst($pet?->species ?? '—'),
                    'breed' => $pet?->breed ?? '—',
                    'size' => $bp->confirmed_size ?? $pet?->groomingSize() ?? '—',
                    'sizeVerified' => $pet?->hasClinicVerifiedSize() ?? false,
                    'groomingEstimate' => app(\App\Services\GroomingTimeEstimate::class)->forVisit($bp),
                    'registeredSize' => $bp->registered_size,
                    'confirmedSize' => $bp->confirmed_size,
                    'furType' => $pet?->fur_type ?? '—',
                    'weight' => $pet?->weight ? $pet->weight.' kg' : '—',
                    'medicalConditions' => $pet?->medical_conditions ?? null,
                    'specialInstructions' => $bp->special_instructions ?? null,
                    'groomerId' => $bp->groomer_id,
                    'petGroomerName' => $bp->groomer?->first_name,
                    'petQueueNumber' => $bp->pet_queue_number ?? $petIndex + 1,
                    'groomingStartedAt' => $bp->grooming_start_time
                        ? Carbon::parse($bp->grooming_start_time)->format('g:i A')
                        : null,
                    'isGroomingStarted' => (bool) $bp->grooming_start_time,
                    'groomingStartedAtIso' => $bp->grooming_start_time,
                    'groomingFinishedAt' => $bp->grooming_end_time
                        ? Carbon::parse($bp->grooming_end_time)->format('g:i A')
                        : null,
                    'groomingFinishedAtIso' => $bp->grooming_end_time,
                    'isGroomingFinished' => (bool) $bp->grooming_end_time,
                    'cancellationReason' => $bp->cancellation_reason,
                    'cancelledAt' => $bp->cancelled_at?->toIso8601String(),
                    'cancelledBy' => $bp->relationLoaded('cancelledBy') && $bp->cancelledBy
                        ? trim($bp->cancelledBy->first_name.' '.$bp->cancelledBy->last_name) : null,
                    'groomingState' => $groomingState,
                    'grooming_state' => $groomingState,
                    'groomingStateLabel' => $this->groomingStateLabel($groomingState),
                    'grooming_state_label' => $this->groomingStateLabel($groomingState),
                ];
            })->values(),
            'services' => $bookedServices->map(function ($bs) use ($bpetsById, $paidTotal, $paidServicesTotal, $canUseSavedServicePrices, $billableServices) {
                $bookingPet = $bpetsById->get($bs->booking_pet_id);
                $pet = $bookingPet?->pet;
                $bs->setRelation('bookingPet', $bookingPet);
                $paymentPriceRules = $bs->service
                    ? app(\App\Services\GroomingServicePriceResolver::class)->paymentPriceRules(
                        $bs, $bookingPet?->confirmed_size ?? $bookingPet?->registered_size ?? $pet?->groomingSize(),
                    ) : null;
                $savedPrice = (float) ($bs->price_at_booking ?? 0);
                $paidPrice = null;
                $paidPriceSource = null;

                if ($bookingPet?->grooming_state === BookingPet::GROOMING_STATE_CANCELLED) {
                    $paidPrice = null;
                } elseif ($paidTotal && $billableServices->count() === 1) {
                    $paidPrice = $paidServicesTotal;
                    $paidPriceSource = 'payment_total';
                } elseif ($savedPrice > 0 && $canUseSavedServicePrices) {
                    $paidPrice = $savedPrice;
                    $paidPriceSource = $paidTotal ? 'service_line' : 'booking_price';
                }

                return [
                    'id' => $bs->booking_service_id,
                    'bookingServiceId' => $bs->booking_service_id,
                    'booking_service_id' => $bs->booking_service_id,
                    'bookingPetId' => $bs->booking_pet_id,
                    'booking_pet_id' => $bs->booking_pet_id,
                    'petId' => $pet?->pet_id,
                    'pet_id' => $pet?->pet_id,
                    'petName' => $pet?->pet_name ?? '',
                    'pet_name' => $pet?->pet_name ?? '',
                    'serviceId' => $bs->service?->service_id,
                    'service_id' => $bs->service?->service_id,
                    'slug' => $bs->service?->slug,
                    'serviceSlug' => $bs->service?->slug,
                    'service_slug' => $bs->service?->slug,
                    'serviceName' => $bs->service?->service_name ?? 'Grooming Service',
                    'service_name' => $bs->service?->service_name ?? 'Grooming Service',
                    'description' => $bs->service?->description,
                    'name' => $bs->service?->service_name ?? '—',
                    'serviceKind' => $bs->addon_id !== null
                        ? 'ala_carte'
                        : ($bs->service?->kind === 'package'
                            ? 'package' : 'ala_carte'),
                    'priceAtBooking' => $bs->price_at_booking,
                    'price_at_booking' => $bs->price_at_booking,
                    'priceMinAtBooking' => $bs->price_min_at_booking,
                    'price_min_at_booking' => $bs->price_min_at_booking,
                    'priceMaxAtBooking' => $bs->price_max_at_booking,
                    'paymentPriceRules' => $paymentPriceRules,
                    'price_max_at_booking' => $bs->price_max_at_booking,
                    'durationMinutes' => (int) ($bs->service?->duration_minutes ?? 60),
                    'paidPrice' => $paidPrice,
                    'paid_price' => $paidPrice,
                    'paidPriceSource' => $paidPriceSource,
                    'paid_price_source' => $paidPriceSource,
                    'paymentTotal' => $paidTotal,
                    'payment_total' => $paidTotal,
                ];
            })->values(),
        ];
    }

    /**
     * Build the schedule-card pet type label from every pet in the booking.
     */
    private function formatPetTypeSummary($bookingPets): string
    {
        $typeCounts = collect($bookingPets)
            ->map(fn ($bookingPet) => strtolower(trim((string) ($bookingPet->pet?->species ?? ''))))
            ->filter()
            ->countBy();

        if ($typeCounts->isEmpty()) {
            return '—';
        }

        // Keep the two supported clinic pet types in the expected display order.
        $orderedTypes = collect(['dog', 'cat'])
            ->filter(fn ($type) => $typeCounts->has($type))
            ->merge($typeCounts->keys()->reject(fn ($type) => in_array($type, ['dog', 'cat'], true)));

        $labels = $orderedTypes
            ->map(function ($type) use ($typeCounts) {
                $label = ucfirst($type);

                return $typeCounts->get($type) > 1 ? $label.'s' : $label;
            })
            ->values();

        if ($labels->count() === 1) {
            return $labels->first();
        }

        if ($labels->count() === 2) {
            return $labels->first().' and '.$labels->last();
        }

        return $labels->slice(0, -1)->implode(', ').', and '.$labels->last();
    }

    // Formats a booking record for the archive page
    private function formatArchivedBooking(Booking $booking): array
    {
        $paymentSummary = $this->canUseSettledArchivePaymentSummary($booking)
            ? $this->settledArchivePaymentSummary($booking)
            : $this->paymentReadiness()->summarize($booking);
        $formatted = $this->formatBooking(
            $booking,
            $paymentSummary,
        );
        $formatted['archivedAt'] = $booking->archived_at
            ? Carbon::parse($booking->archived_at)->format('M j, Y g:i A')
            : '—';
        $formatted['dropOffAtIso'] = $booking->dropped_off_at
            ? Carbon::parse($booking->dropped_off_at)->toIso8601String() : null;
        $formatted['startedAtIso'] = $booking->grooming_started_at
            ? Carbon::parse($booking->grooming_started_at)->toIso8601String() : null;
        $formatted['completedAtIso'] = $booking->grooming_finished_at
            ? Carbon::parse($booking->grooming_finished_at)->toIso8601String() : null;
        $paidPayment = $this->paidPaymentForBooking($booking);
        if ($paidPayment) {
            $formatted['payment']['notes'] = $paidPayment->notes;
            $formatted['payment']['changeAmount'] = $paidPayment->change_amount;
        }
        return $formatted;
    }

    private function ownerAccountDeleted(Booking $booking): bool
    {
        $userAttributes = $booking->user?->getAttributes() ?? [];
        if (($userAttributes['account_deleted_at'] ?? null) !== null) {
            return true;
        }

        $unregisteredAttributes = $booking->walkin?->unregisteredCustomer?->getAttributes() ?? [];

        return ($unregisteredAttributes['account_deleted_at'] ?? null) !== null;
    }

    private function canUseSettledArchivePaymentSummary(Booking $booking): bool
    {
        $bookingPets = $booking->bookingPets ?? collect();
        $hasOnlyOrdinaryFinishedPets = $bookingPets->isNotEmpty()
            && $bookingPets->every(fn (BookingPet $bookingPet) => (string) $bookingPet->grooming_state
                === BookingPet::GROOMING_STATE_FINISHED
                && $bookingPet->grooming_end_time !== null);
        $hasOnlyPositiveServiceSnapshots = ($booking->bookingServices ?? collect())
            ->every(fn ($bookingService) => $bookingService->addon_id === null
                && $bookingService->service_id !== null
                && (float) $bookingService->price_at_booking > 0);
        $paidPayment = $this->paidPaymentForBooking($booking);
        $recordedTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : ((bool) $booking->paid ? (float) $booking->total_amount : null);
        $snapshotTotal = (float) ($booking->bookingServices ?? collect())
            ->sum(fn ($bookingService) => (float) $bookingService->price_at_booking);
        $productTotal = (float) ($paidPayment?->products ?? collect())->sum('subtotal');
        $hasConsistentSettledTotal = $recordedTotal !== null
            && abs($recordedTotal - $productTotal - $snapshotTotal) <= 0.01;

        return $hasOnlyOrdinaryFinishedPets
            && $hasOnlyPositiveServiceSnapshots
            && $hasConsistentSettledTotal;
    }

    /**
     * Reconstruct the settled payment shape from relations already loaded for
     * the archive. This keeps the legacy booking response contract without
     * rerunning live readiness queries for every historical booking.
     */
    private function settledArchivePaymentSummary(Booking $booking): array
    {
        $bookingPets = ($booking->bookingPets ?? collect())
            ->sortBy('booking_pet_id')
            ->values();
        $servicesByPet = ($booking->bookingServices ?? collect())
            ->sortBy('booking_service_id')
            ->groupBy('booking_pet_id');
        $paidPayment = $this->paidPaymentForBooking($booking);
        $settledTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : (float) $booking->total_amount;

        $pets = $bookingPets->map(function (BookingPet $bookingPet) use ($servicesByPet) {
            $groomingState = $this->bookingPetGroomingState($bookingPet);
            $serviceLines = collect($servicesByPet->get($bookingPet->booking_pet_id, collect()))
                ->map(function ($bookingService) {
                    $savedPrice = (float) ($bookingService->price_at_booking ?? 0);
                    $isAddon = $bookingService->addon_id !== null;

                    return [
                        'booking_service_id' => (int) $bookingService->booking_service_id,
                        'line_type' => $isAddon ? 'add_on' : 'service',
                        'service_id' => $bookingService->service_id !== null
                            ? (int) $bookingService->service_id
                            : null,
                        'addon_id' => $bookingService->addon_id !== null
                            ? (int) $bookingService->addon_id
                            : null,
                        'label' => $isAddon
                            ? "Add-on #{$bookingService->addon_id}"
                            : ($bookingService->service?->service_name
                                ?? "Service #{$bookingService->service_id}"),
                        'price_at_booking' => number_format($savedPrice, 2, '.', ''),
                        'price_source' => $savedPrice > 0 ? 'booking_snapshot' : 'unpriced',
                    ];
                })
                ->values();
            $petSubtotal = $serviceLines->sum(
                fn (array $line) => (float) $line['price_at_booking'],
            );
            return [
                'booking_pet_id' => (int) $bookingPet->booking_pet_id,
                'pet_id' => $bookingPet->pet_id !== null ? (int) $bookingPet->pet_id : null,
                'pet_name' => $bookingPet->pet?->pet_name ?? 'Pet',
                'pet_species' => $bookingPet->pet?->species,
                'grooming_state' => $groomingState,
                'grooming_state_label' => $this->groomingStateLabel($groomingState),
                'grooming_finish_time' => $bookingPet->grooming_end_time?->toIso8601String(),
                'payment_kind' => 'finished',
                'payment_ready' => true,
                'payment_blocked_reason' => null,
                'service_breakdown' => $serviceLines->all(),
                'original_pet_subtotal' => number_format($petSubtotal, 2, '.', ''),
                'final_pet_charge' => number_format($petSubtotal, 2, '.', ''),
            ];
        })->values();

        return [
            'payment_ready' => true,
            'payment_blocked_reason' => null,
            'final_booking_total' => number_format($settledTotal - (float) ($paidPayment?->products ?? collect())->sum('subtotal'), 2, '.', ''),
            'pets' => $pets->all(),
        ];
    }

    private function paidPaymentForBooking(Booking $booking): ?Payment
    {
        if ($booking->relationLoaded('payments')) {
            return $booking->payments
                ->where('payment_status', 'paid')
                ->sortByDesc(fn ($payment) => $payment->paid_at
                    ? Carbon::parse($payment->paid_at)->timestamp
                    : 0)
                ->first();
        }

        return $booking->payments()
            ->where('payment_status', 'paid')
            ->orderByDesc('paid_at')
            ->first();
    }

    private function recentActivity(): array
    {
        $payments = Payment::with(['booking.user', 'booking.walkin'])
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at')
            ->orderBy('paid_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Payment $payment) {
                $booking = $payment->booking;
                $ownerLastName = $booking?->user?->last_name ?: $this->ownerName($booking);

                return [
                    'id' => 'payment-'.$payment->getKey(),
                    'type' => 'payment',
                    'title' => 'Payment collected',
                    'subtitle' => "\u{20B1}".number_format((float) $payment->total_amount).' - '.($ownerLastName ?: 'Customer'),
                    'time' => $payment->paid_at,
                ];
            });

        $checkIns = Booking::with(['user', 'walkin', 'bookingPets.pet'])
            ->whereNotNull('dropped_off_at')
            ->orderBy('dropped_off_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'check-in-'.$booking->booking_id,
                    'type' => 'queued',
                    'title' => 'Checked in',
                    'subtitle' => $this->petNames($booking).' - '.($this->ownerName($booking) ?: 'Customer'),
                    'time' => $booking->dropped_off_at,
                ];
            });

        $groomingStarted = Booking::with(['bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_started_at')
            ->orderBy('grooming_started_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'grooming-started-'.$booking->booking_id,
                    'type' => 'in_progress',
                    'title' => 'Grooming started',
                    'subtitle' => $this->petNames($booking).' - '.$this->serviceLabel($booking),
                    'time' => $booking->grooming_started_at,
                ];
            });

        $completed = Booking::with(['bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_finished_at')
            ->orderBy('grooming_finished_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'completed-'.$booking->booking_id,
                    'type' => 'completed',
                    'title' => 'Grooming Service Completed',
                    'subtitle' => $this->petNames($booking).' - '.$this->serviceLabel($booking),
                    'time' => $booking->grooming_finished_at,
                ];
            });

        return $payments
            ->concat($checkIns)
            ->concat($groomingStarted)
            ->concat($completed)
            ->filter(fn ($activity) => ! empty($activity['time']))
            ->sortByDesc(fn ($activity) => Carbon::parse($activity['time'])->timestamp)
            ->take(5)
            ->map(function ($activity) {
                $time = Carbon::parse($activity['time']);

                return [
                    ...$activity,
                    'createdAt' => $time->toIso8601String(),
                    'timeLabel' => $time->format('g:i A'),
                ];
            })
            ->values()
            ->all();
    }

    private function ownerName(?Booking $booking): string
    {
        if ($booking?->user) {
            return trim(($booking->user->first_name ?? '').' '.($booking->user->last_name ?? ''));
        }

        if ($booking?->walkin) {
            return trim("{$booking->walkin->fname} {$booking->walkin->lname}");
        }

        return '';
    }

    private function petNames(Booking $booking): string
    {
        $names = ($booking->bookingPets ?? collect())
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->map(fn ($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return 'your pet';
        }

        if ($names->count() === 1) {
            return $names->first();
        }

        if ($names->count() === 2) {
            return $names->implode(' and ');
        }

        return $names->slice(0, -1)->implode(', ').', and '.$names->last();
    }

    private function hasMultiplePets(Booking $booking): bool
    {
        return ($booking->bookingPets ?? collect())
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->map(fn ($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->unique()
            ->count() > 1;
    }

    private function serviceLabel(Booking $booking): string
    {
        $services = ($booking->bookingServices ?? collect())
            ->map(fn ($bookingService) => $bookingService->service?->service_name)
            ->filter()
            ->unique()
            ->values();

        return $services->isNotEmpty() ? $services->implode(', ') : 'Grooming';
    }

    private function bookingPetGroomingState(BookingPet $bookingPet): string
    {
        $state = (string) $bookingPet->grooming_state;

        if (BookingPet::isValidGroomingState($state)) {
            return $state;
        }

        if ($bookingPet->grooming_end_time !== null) {
            return BookingPet::GROOMING_STATE_FINISHED;
        }

        if ($bookingPet->grooming_start_time !== null) {
            return BookingPet::GROOMING_STATE_IN_PROGRESS;
        }

        return BookingPet::GROOMING_STATE_NOT_STARTED;
    }

    private function groomingStateLabel(string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_IN_PROGRESS => 'In progress',
            BookingPet::GROOMING_STATE_FINISHED => 'Finished',
            BookingPet::GROOMING_STATE_CANCELLED => 'Cancelled',
            default => 'Not started',
        };
    }

    private function paymentReadiness(): GroomingPaymentReadinessService
    {
        return app(GroomingPaymentReadinessService::class);
    }

    // Maps DB status values to the tab keys the frontend uses
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'incoming',
            'checked_in' => 'queued',
            'in_progress' => 'in-progress',
            'for_pickup' => 'for-pickup',
            'for_payment' => 'for-payment',
            'released' => 'released',
            default => $status,
        };
    }
}
