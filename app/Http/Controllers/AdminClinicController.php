<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicAttachment;
use App\Models\ClinicRecord;
use App\Models\ClinicVital;
use App\Models\User;
use App\Models\VaccinationRecord;
use Illuminate\Validation\Rule;
use App\Models\Notification;
use App\Models\InventoryItem;
use Illuminate\Validation\ValidationException;
use App\Models\Pet;
use App\Services\ClinicAppointmentSequence;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminClinicController extends Controller
{
    private ?bool $hasClinicCharges = null;
    private ?bool $hasConfinements = null;
    private array $resolvedAppointmentRelations = [];

    // ── Queue index ──────────────────────────────────────────────────────────

    public function index()
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $with = $this->appointmentRelations();

        $incoming = ClinicAppointment::with($with)
            ->expected()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $queued = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'checked_in')
            ->orderBy('queue_number')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $inConsultation = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'in_consultation')
            ->orderBy('consultation_started_at')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $forPayment = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'for_payment')
            ->orderBy('consultation_finished_at')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $completed = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'completed')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        return response()->json([
            'success' => true,
            'incoming' => $incoming,
            'queued' => $queued,
            'in_consultation' => $inConsultation,
            'for_payment' => $forPayment,
            'completed' => $completed,
        ]);
    }

    /** Paginated, newest-first patient directory for the Clinic Records landing view. */
    public function records(Request $request)
    {
        $perPage = 25;
        $activePets = fn () => Pet::query()
            ->where('is_archived', false)
            ->where(fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_archived', false))
                ->orWhereHas('unregisteredCustomer', fn ($u) => $u->where('is_archived', false)));

        // Page over owners (not pets) so each owner appears once with all of their pets.
        $page = $activePets()
            ->select('user_id', 'unregistered_customer_id', DB::raw('MAX(pet_id) as latest_pet_id'))
            ->groupBy('user_id', 'unregistered_customer_id')
            ->orderByDesc('latest_pet_id')
            ->simplePaginate($perPage);

        $ownerKeys = $page->getCollection();
        $userIds = $ownerKeys->pluck('user_id')->filter()->values();
        $unregisteredIds = $ownerKeys->whereNull('user_id')->pluck('unregistered_customer_id')->filter()->values();

        $petsByOwner = $ownerKeys->isEmpty() ? collect() : $activePets()
            ->with(['user:user_id,first_name,last_name,phone,email', 'unregisteredCustomer:id,first_name,last_name,phone,email'])
            ->where(fn ($q) => $q->whereIn('user_id', $userIds)
                ->orWhere(fn ($u) => $u->whereNull('user_id')->whereIn('unregistered_customer_id', $unregisteredIds)))
            ->orderByDesc('pet_id')
            ->get()
            ->groupBy(fn (Pet $pet) => $pet->user_id ? "u:{$pet->user_id}" : "c:{$pet->unregistered_customer_id}");

        $rows = $ownerKeys->map(function ($key) use ($petsByOwner) {
            $pets = $petsByOwner->get($key->user_id ? "u:{$key->user_id}" : "c:{$key->unregistered_customer_id}", collect());
            $first = $pets->first();
            $owner = $first?->user ?? $first?->unregisteredCustomer;
            return [
                'owner' => [
                    'id' => $first?->user ? $first->user->user_id : $owner?->id,
                    'recordType' => $first?->user ? 'registered' : 'unregistered',
                    'fullName' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                    'phone' => $owner?->phone,
                    'email' => $owner?->email,
                ],
                'pets' => $pets->map(fn (Pet $pet) => [
                    'id' => $pet->pet_id,
                    'petName' => $pet->pet_name,
                    'species' => $pet->species,
                    'breed' => $pet->breed,
                ])->values(),
            ];
        })->values();

        return response()->json(['success' => true, 'rows' => $rows, 'has_more' => $page->hasMorePages()]);
    }

    /** Lightweight, case-oriented replacement for the old queue response. */
    public function activeCases()
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $with = $this->appointmentRelations(true);
        $arrivals = ClinicAppointment::with($with)
            ->expected()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ClinicAppointment $arrival) => $this->formatAppointment($arrival, true))
            ->values();
        $cases = ClinicAppointment::query()
            ->with($with)
            ->operational()
            ->when($this->hasConfinements, fn ($query) => $query
                ->whereDoesntHave('confinement', fn ($episode) => $episode->whereNull('discharged_at')))
            ->orderBy('created_at')
            ->get()
            ->map(fn (ClinicAppointment $case) => $this->formatAppointment($case, true))
            ->values();

        return response()->json(['success' => true, 'arrivals' => $arrivals, 'cases' => $cases, 'today' => now()->toDateString(),
            'confined_count' => $this->hasConfinements ? ClinicAppointment::confined()->count() : 0]);
    }

    public function showCase(int $id)
    {
        $case = ClinicAppointment::with($this->appointmentRelations())->findOrFail($id);
        return response()->json(['case' => $this->formatAppointment($case)]);
    }

    public function confinedCases(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $cases = ClinicAppointment::with($this->appointmentRelations())
            ->confined()
            ->orderBy('created_at')->orderBy('id')->paginate(20);
        $cases->setCollection($cases->getCollection()->map(fn ($case) => $this->formatAppointment($case)));
        return response()->json($cases);
    }

    public function currentPetCase(int $petId)
    {
        Pet::query()->findOrFail($petId);
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $case = ClinicAppointment::with($this->appointmentRelations())
            ->blocking()->where('pet_id', $petId)->orderBy('id')->first();

        $isArrival = $case?->status === 'waiting_to_arrive';
        $formatted = $case ? $this->formatAppointment($case) : null;

        return response()->json(['case' => $isArrival ? null : $formatted, 'arrival' => $isArrival ? $formatted : null]);
    }

    public function createCase(Request $request, ClinicAppointmentSequence $sequence)
    {
        $data = $request->validate([
            'pet_id' => ['required', 'integer', 'exists:pets,pet_id'],
            'case_type' => ['required', 'in:consultation,vaccination'],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
        ]);

        app(\App\Services\PreRegistrationExpiry::class)->expire();
        return app(\App\Services\DailyPetQueue::class)->runForDate(now()->toDateString(), function () use ($data, $sequence) {
            $pet = Pet::query()->whereKey($data['pet_id'])->where('is_archived', false)->firstOrFail();
            $existing = ClinicAppointment::query()
                ->where('pet_id', $pet->pet_id)
                ->blocking()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $case = $this->formatAppointment($existing);
                if ($existing->status === 'waiting_to_arrive') {
                    return response()->json([
                        'success' => false,
                        'code' => 'pre_registration_exists',
                        'message' => "{$pet->pet_name} already has a pre-registration waiting for arrival ({$existing->appointment_reference}).",
                        'arrival' => $case,
                    ], 409);
                }
                $label = ['online_request' => 'online request', 'vaccination' => 'vaccination'][$case['case_type']] ?? 'consultation';

                return response()->json([
                    'success' => false,
                    'code' => 'active_case_exists',
                    'message' => "{$pet->pet_name} already has an ongoing {$label} case ({$existing->appointment_reference}).",
                    'case' => $case,
                ], 409);
            }

            app(\App\Services\OperationalCapacity::class)->assertCanAccept('clinic', 1);
            $reserved = $sequence->reserve(now()->toDateString(), true);
            $case = ClinicAppointment::create([
                'appointment_reference' => $reserved['appointment_reference'],
                'appointment_type' => 'walk_in',
                'case_type' => $data['case_type'],
                'status' => 'checked_in',
                'queue_number' => $reserved['queue_number'],
                'appointment_date' => now()->toDateString(),
                'user_id' => $pet->user_id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => now(),
            ]);

            return response()->json(['success' => true, 'created' => true, 'case' => $this->formatAppointment($case)], 201);
        });
    }

    public function startCase(int $id)
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $case = $this->beginConsultation($id);
        return response()->json(['success' => true, 'case' => $this->formatAppointment($case)]);
    }

    private function beginConsultation(int $id): ClinicAppointment
    {
        return DB::transaction(function () use ($id) {
            $case = ClinicAppointment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($case->status, ClinicAppointment::TERMINAL_STATUSES, true)) {
                throw new HttpResponseException(response()->json(['success' => false, 'message' => 'This case is already closed.'], 409));
            }
            if ($case->status !== 'checked_in') {
                throw ValidationException::withMessages(['status' => 'Appointment must be checked in before starting consultation.']);
            }
            $case->update(['status' => 'in_consultation', 'consultation_started_at' => now()]);
            return $case->fresh($this->appointmentRelations());
        });
    }

    public function finishCase(int $id)
    {
        $case = DB::transaction(function () use ($id) {
            $appointment = ClinicAppointment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($appointment->status, ClinicAppointment::TERMINAL_STATUSES, true)) {
                throw new HttpResponseException(response()->json(['success' => false, 'message' => 'This case is already closed.'], 409));
            }
            $appointment->prepareForPayment();
            return $appointment;
        });
        return response()->json(['success' => true, 'case' => $this->formatAppointment($case->fresh())]);
    }

    public function archivedIndex(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $date = trim((string) $request->query('date', ''));

        $query = ClinicAppointment::with($this->appointmentRelations())
            ->whereIn('status', ClinicAppointment::TERMINAL_STATUSES);

        if (Schema::hasTable('vaccination_records')) {
            $query->withCount(['vaccinationRecords as treatment_count' => fn ($query) => $query->published()->notVoided()]);
        }
        if (Schema::hasTable('clinic_case_services')) {
            $query->withCount(['caseServices as service_count' => fn ($query) => $query->where('status', 'performed')]);
        }

        if ($date !== '') {
            $query->whereDate('appointment_date', $date);
        }

        if ($search !== '') {
            $query->where(function ($archiveQuery) use ($search) {
                $like = "%{$search}%";

                $archiveQuery
                    ->where('appointment_reference', 'like', $like)
                    ->orWhere('chief_complaint', 'like', $like)
                    ->orWhereHas('record', fn ($query) => $query
                        ->where('chief_complaint', 'like', $like)
                        ->orWhere('working_diagnosis', 'like', $like))
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('walkin', function ($walkinQuery) use ($like) {
                        $walkinQuery
                            ->where('fname', 'like', $like)
                            ->orWhere('lname', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('pet', function ($petQuery) use ($like) {
                        $petQuery
                            ->where('pet_name', 'like', $like)
                            ->orWhere('species', 'like', $like)
                            ->orWhere('breed', 'like', $like);
                    });
            });
        }

        $archived = $query
            ->orderByDesc('appointment_date')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ClinicAppointment $appointment) => [
                ...$this->formatAppointment($appointment),
                'treatment_count' => $appointment->treatment_count ?? 0,
                'service_count' => $appointment->service_count ?? 0,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'archived' => $archived,
            'total' => $archived->count(),
        ]);
    }

    // ── Status transitions ───────────────────────────────────────────────────

    public function checkIn(int $id, ClinicAppointmentSequence $clinicSequence)
    {
        $appt = app(\App\Services\DailyPetQueue::class)->runForDate(now()->toDateString(), function () use ($id, $clinicSequence) {
            $appointment = ClinicAppointment::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'waiting_to_arrive') {
                return null;
            }

            if ($appointment->appointment_date->toDateString() !== now()->toDateString()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['check_in' => 'Check-in is only allowed on the pre-registration date.']);
            }
            app(\App\Services\OperationalCapacity::class)->assertCanAccept('clinic', 1);

            $queueNumber = $appointment->queue_number;

            if (! $queueNumber) {
                $queueNumber = $clinicSequence->nextQueueNumber(
                    $appointment->appointment_date->toDateString(),
                );
            }

            $appointment->update([
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'checked_in_at' => now(),
            ]);

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Appointment is not in Waiting to Arrive status.'], 422);
        }

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'timeWindow', 'vitals', 'record']))]);
    }

    public function startConsultation(int $id)
    {
        $appt = $this->beginConsultation($id);

        return response()->json([
            'success' => true,
            'message' => 'Clinic consultation started.',
            'appointment' => $this->formatAppointment($appt),
        ]);
    }

    public function finishConsultation(int $id)
    {
        $appt = DB::transaction(function () use ($id) {
            $appointment = ClinicAppointment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $appointment->prepareForPayment();
            return $appointment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Clinic consultation finished.',
            'appointment' => $this->formatAppointment($appt->fresh($this->appointmentRelations())),
        ]);
    }

    public function markPaid(Request $request, int $id)
    {
        $request->validate([
            'total_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_method' => ['required', 'in:cash'],
            'amount_paid' => ['required_if:payment_method,cash', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
        ]);

        $appt = DB::transaction(function () use ($id, $request) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            \App\Models\GatewayPayment::assertClinicUnlocked($appointment);
            if ($appointment->status !== 'for_payment') {
                return null;
            }

            $total = round((float) $appointment->charges()->sum('amount'), 2);
            if (abs($total - (float) $request->total_amount) > 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'total_amount' => 'The charges have changed. Reload the payment breakdown before recording payment.',
                ]);
            }

            if ($request->payment_method === 'cash') {
                $received = (float) $request->amount_paid;
                if (round($received * 100) < round($total * 100)) {
                    throw ValidationException::withMessages(['amount_paid' => 'Cash received is less than the amount due.']);
                }
                $maximum = \App\Support\PaymentAmountLimit::maximumFor($total);
                if ($received > $maximum) {
                    throw ValidationException::withMessages([
                        'amount_paid' => 'Amount paid cannot exceed ₱'.number_format($maximum, 2).'.',
                    ]);
                }
            }

            app(\App\Services\ClinicPaymentSettlementService::class)->settle(
                $appointment, number_format($total, 2, '.', ''), 'cash', $request->user()->user_id, (string) $request->amount_paid,
            );

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Appointment is not For Payment.'], 422);
        }

        $recorded = $this->formatAppointment($appt->fresh($this->appointmentRelations()));
        // The processor comes from the authenticated request, never a submitted ID.
        $recorded['processed_by'] = [
            'name' => trim(($request->user()->first_name ?? '').' '.($request->user()->last_name ?? '')),
        ];

        return response()->json(['success' => true, 'appointment' => $recorded]);
    }

    public function cancel(Request $request, int $id)
    {
        $appt = DB::transaction(function () use ($id) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            \App\Models\GatewayPayment::assertClinicUnlocked($appointment);
            if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
                return null;
            }
            if ($appointment->hasActiveConfinement()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Discharge the confined patient before cancelling this case.']);
            }

            $appointment->update(['status' => 'cancelled']);
            Notification::createForClinic(
                $appointment,
                Notification::TYPE_CLINIC_CANCELLED,
                "Clinic appointment {$appointment->appointment_reference} was cancelled by clinic staff.",
            );

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel a completed or already-cancelled appointment.'], 422);
        }

        return response()->json(['success' => true]);
    }

    // ── Medical record ───────────────────────────────────────────────────────

    public function medicineOptions(Request $request)
    {
        $q = trim($request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['data' => []]);
        $items = InventoryItem::query()->where('category', 'medicine')
            ->where('item_name', 'like', "%{$q}%")
            ->with('weightPrices')->orderBy('item_name')->get();
        $batchBalances = app(\App\Services\InventoryBatchBalanceService::class);
        $balances = $batchBalances->forItems($items->pluck('item_id'));
        return response()->json(['data' => $items->map(fn ($item) => [
            'item_id' => $item->item_id, 'item_name' => $item->item_name, 'unit' => $item->unit,
            'medicine_type' => $item->medicine_type,
            ...$batchBalances->treatmentAvailability($item, $balances[$item->item_id]),
            'strength_concentration' => $item->strength_concentration,
            'pricing_method' => $item->pricing_method, 'weight_pricing_mode' => $item->weight_pricing_mode ?? 'legacy', 'selling_price' => $item->selling_price,
            'weight_prices' => $item->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->values(),
        ])]);
    }

    public function saveRecord(Request $request, int $id)
    {
        $appointment = ClinicAppointment::findOrFail($id);
        $existing = $appointment->record;
        $actor = $request->user();
        $veterinarians = User::activeVeterinarian()->get(['user_id', 'first_name', 'last_name'])->keyBy('user_id');
        $request->merge(['primary_veterinarian_id' => $actor->isVeterinarian()
            ? $actor->user_id : $request->input('primary_veterinarian_id', $existing?->primary_veterinarian_id)]);
        $data = $request->validate([
            'primary_veterinarian_id' => ['nullable', 'required_if_accepted:finish_case', 'integer', Rule::in($veterinarians->keys()->all())],
            'additional_veterinarian_ids' => ['sometimes', 'array', 'list', 'max:20'],
            'additional_veterinarian_ids.*' => ['required', 'integer', 'distinct', Rule::in($veterinarians->keys()->all()), 'different:primary_veterinarian_id'],
            'relevant_history' => ['nullable', 'string', 'max:2000'],
            'reported_signs' => ['nullable', 'string', 'max:2000'],
            'physical_exam_findings' => ['nullable', 'string', 'max:2000'],
            'differential_diagnoses' => ['nullable', 'string', 'max:2000'],
            'working_diagnosis' => ['nullable', 'string', 'max:2000'],
            'diagnostics' => ['prohibited'],
            'prescriptions' => ['sometimes', 'array', 'list', 'max:100'],
            'prescriptions.*' => ['required', 'array:medicine,strength_concentration,dose,route,frequency,duration,quantity,instructions'],
            'prescriptions.*.medicine' => ['required', 'string', 'max:200'],
            'prescriptions.*.strength_concentration' => ['required', 'string', 'max:200'],
            'prescriptions.*.dose' => ['required', 'string', 'max:200'],
            'prescriptions.*.route' => ['required', Rule::in(VaccinationRecord::ADMINISTRATION_ROUTES)],
            'prescriptions.*.frequency' => ['required', 'string', 'max:200'],
            'prescriptions.*.duration' => ['required', 'string', 'max:200'],
            'prescriptions.*.quantity' => ['required', 'string', 'max:200', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?(?:\s+\S.*)?$/',
                function ($attribute, $value, $fail) {
                    if ((float) $value <= 0 || (float) $value > 99999999.99) $fail('Quantity must be a positive amount, optionally followed by a unit (e.g. 14 tablets).');
                }],
            'prescriptions.*.instructions' => ['nullable', 'string', 'max:2000'],
            'chief_complaint' => [Rule::excludeIf($appointment->appointment_type === 'pre_registered'), 'nullable', 'string', 'max:1200'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'treatment_given' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addMonths(3)->toDateString()],
            'follow_up_notes' => ['nullable', 'string', 'max:1000'],
            'vet_notes' => ['nullable', 'string', 'max:2000'],
            'finish_case' => ['sometimes', 'boolean'],
            'medicines' => ['prohibited'],
            // Vitals
            'weight_kg' => ['nullable', 'required_if_accepted:finish_case', new \App\Rules\ValidPetWeight(requireSpecies: false)],
            'temperature_c' => ['nullable', 'required_if_accepted:finish_case', 'numeric', 'decimal:0,1', 'min:0', 'max:999.9'],
            'heart_rate_bpm' => ['nullable', 'required_if_accepted:finish_case', 'integer', 'min:0', 'max:65535'],
            'respiratory_rate_bpm' => ['nullable', 'required_if_accepted:finish_case', 'integer', 'min:0', 'max:65535'],
            'body_condition_score' => ['nullable', 'integer', 'min:1', 'max:9'],

        ], [
            'diagnostics.prohibited' => 'Add diagnostic services through Billing > Clinic Services. Record imaging in Diagnostic Imaging.',
            'medicines.prohibited' => 'Record medicines from Inventory through Treatment. Medical Record does not create treatment charges.',
            'follow_up_date.after_or_equal' => 'The follow-up date cannot be in the past.',
            'follow_up_date.before_or_equal' => 'The follow-up date must be within 3 months from today.',
        ]);

        return DB::transaction(function () use ($data, $id, $actor, $veterinarians) {
            $appt = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            \App\Models\GatewayPayment::assertClinicUnlocked($appt);
            if (! empty($data['finish_case']) && $appt->status === 'for_payment' && ! $appt->paid && ! $appt->archived_at) {
                // A lost response must not repeat clinical finalization or overwrite the saved visit.
                return response()->json(['success' => true, 'record' => $this->formatRecord($appt->record, $appt->id)]);
            }
            $this->assertClinicalContentEditable($appt);

            $existing = $appt->record;
            $name = fn (User $user) => trim($user->first_name.' '.$user->last_name) ?: "User #{$user->user_id}";
            $primary = $veterinarians->get($data['primary_veterinarian_id'] ?? null);
            $clinical = [];
            foreach (['relevant_history', 'reported_signs', 'physical_exam_findings', 'differential_diagnoses', 'working_diagnosis', 'prescriptions'] as $field) {
                if (array_key_exists($field, $data)) $clinical[$field] = $data[$field];
            }
            if (array_key_exists('additional_veterinarian_ids', $data)) {
                $clinical['additional_veterinarians'] = array_map(fn ($userId) => [
                    'user_id' => (int) $userId, 'name' => $name($veterinarians->get($userId)),
                ], $data['additional_veterinarian_ids']);
            } else {
                $clinical['additional_veterinarians'] = array_values(array_filter($existing?->additional_veterinarians ?? [],
                    fn ($vet) => (int) $vet['user_id'] !== (int) $primary?->user_id));
            }
            // Preserve legacy API consumers while keeping the assessment and exam fields canonical.
            $clinical['working_diagnosis'] = array_key_exists('working_diagnosis', $data)
                ? $data['working_diagnosis'] : (array_key_exists('diagnosis', $data) ? $data['diagnosis'] : ($existing?->working_diagnosis ?? $existing?->diagnosis));
            $clinical['physical_exam_findings'] = array_key_exists('physical_exam_findings', $data)
                ? $data['physical_exam_findings'] : (array_key_exists('findings', $data) ? $data['findings'] : ($existing?->physical_exam_findings ?? $existing?->findings));
            $record = ClinicRecord::updateOrCreate(
                ['clinic_appointment_id' => $appt->id],
                [
                    'chief_complaint' => $appt->appointment_type === 'pre_registered'
                        ? $appt->chief_complaint
                        : (array_key_exists('chief_complaint', $data) ? ($data['chief_complaint'] ?? $appt->chief_complaint) : ($existing?->chief_complaint ?? $appt->chief_complaint)),
                    ...$clinical,
                    'primary_veterinarian_id' => $primary?->user_id,
                    'primary_veterinarian_name' => $primary ? $name($primary) : null,
                    'recorded_by_user_id' => $actor->user_id,
                    'recorded_by_name' => $name($actor),
                    'diagnosis' => $clinical['working_diagnosis'],
                    'findings' => $clinical['physical_exam_findings'],
                    'treatment_given' => array_key_exists('treatment_given', $data) ? $data['treatment_given'] : $existing?->treatment_given,
                    'follow_up_date' => array_key_exists('follow_up_date', $data) ? $data['follow_up_date'] : $existing?->follow_up_date,
                    'follow_up_notes' => array_key_exists('follow_up_notes', $data) ? $data['follow_up_notes'] : $existing?->follow_up_notes,
                    'vet_notes' => array_key_exists('vet_notes', $data) ? $data['vet_notes'] : $existing?->vet_notes,
                ]
            );

            // Upsert vitals
            if (collect([
                'weight_kg',
                'temperature_c',
                'heart_rate_bpm',
                'respiratory_rate_bpm',
                'body_condition_score',
            ])->contains(fn (string $field) => array_key_exists($field, $data))) {
                ClinicVital::updateOrCreate(
                    ['clinic_appointment_id' => $appt->id],
                    [
                        'weight_kg' => array_key_exists('weight_kg', $data) ? $data['weight_kg'] : $appt->vitals?->weight_kg,
                        'temperature_c' => array_key_exists('temperature_c', $data) ? $data['temperature_c'] : $appt->vitals?->temperature_c,
                        'heart_rate_bpm' => array_key_exists('heart_rate_bpm', $data) ? $data['heart_rate_bpm'] : $appt->vitals?->heart_rate_bpm,
                        'respiratory_rate_bpm' => array_key_exists('respiratory_rate_bpm', $data) ? $data['respiratory_rate_bpm'] : $appt->vitals?->respiratory_rate_bpm,
                        'body_condition_score' => array_key_exists('body_condition_score', $data) ? $data['body_condition_score'] : $appt->vitals?->body_condition_score,
                    ]
                );
            }

            if (! empty($data['finish_case'])) {
                $appt->setRelation('record', $record);
                $appt->prepareForPayment();
            }

            $record->load('attachments');

            return response()->json([
                'success' => true,
                'record' => $this->formatRecord($record, $appt->id),
            ]);
        });
    }

    // ── File upload ──────────────────────────────────────────────────────────

    public function uploadAttachment(Request $request, int $id)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,dcm', 'mimes:jpg,jpeg,png,dcm'],
            'label' => ['nullable', 'string', 'max:200'],
            'clinic_case_service_id' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($data, $id) {
            $appt = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertClinicalContentEditable($appt);

            abort_if($appt->paid || $appt->archived_at, 409, 'Imaging files cannot be changed at this case stage.');
            $imaging = $appt->caseServices()->whereKey($data['clinic_case_service_id'])
                ->where('status', 'performed')->imaging()->exists();
            if (! $imaging) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'clinic_case_service_id' => 'Choose an imaging service recorded in this clinic case.',
                ]);
            }

            $actor = request()->user();
            $actorName = trim($actor->first_name.' '.$actor->last_name) ?: "User #{$actor->user_id}";
            $record = ClinicRecord::firstOrCreate(
                ['clinic_appointment_id' => $appt->id],
                ['chief_complaint' => $appt->chief_complaint,
                    'primary_veterinarian_id' => $actor->isVeterinarian() ? $actor->user_id : null,
                    'primary_veterinarian_name' => $actor->isVeterinarian() ? $actorName : null,
                    'recorded_by_user_id' => $actor->user_id, 'recorded_by_name' => $actorName]
            );

            $file = $data['file'];
            $path = $file->store("clinic/attachments/{$appt->id}", 'local');

            if (! $path) {
                return response()->json([
                    'success' => false,
                    'message' => 'The attachment could not be stored.',
                ], 500);
            }

            try {
                $attachment = $record->attachments()->create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size_bytes' => $file->getSize(),
                    'label' => $data['label'] ?? null,
                    'clinic_case_service_id' => $data['clinic_case_service_id'],
                ]);
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($path);

                throw $exception;
            }

            return response()->json([
                'success' => true,
                'attachment' => $this->formatAttachment($attachment, $appt->id),
            ]);
        });
    }

    public function downloadAttachment(int $id, int $attachmentId)
    {
        $attachment = $this->findScopedAttachment($id, $attachmentId);
        $disk = $this->attachmentDisk($attachment->file_path);

        if ($disk === null) {
            $this->attachmentNotFound();
        }

        return Storage::disk($disk)->download(
            $attachment->file_path,
            $this->safeDownloadName($attachment),
            [
                'Content-Type' => $this->safeMimeType($attachment->file_type),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function deleteAttachment(int $id, int $attachmentId)
    {
        return DB::transaction(function () use ($attachmentId, $id) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
            if (! $appointment) {
                $this->attachmentNotFound();
            }
            $this->assertClinicalContentEditable($appointment);

            $attachment = ClinicAttachment::query()
                ->whereKey($attachmentId)
                ->whereHas(
                    'record',
                    fn ($query) => $query->where('clinic_appointment_id', $id),
                )
                ->lockForUpdate()
                ->first();
            if (! $attachment) {
                $this->attachmentNotFound();
            }

            abort_if($attachment->clinic_case_service_id && ($appointment->paid || $appointment->archived_at),
                409, 'Imaging files cannot be changed at this case stage.');

            $disk = $this->attachmentDisk($attachment->file_path);
            if ($disk !== null && ! Storage::disk($disk)->delete($attachment->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The attachment could not be deleted.',
                ], 500);
            }

            $attachment->delete();
            $record = $attachment->record;
            $record->diagnostics = array_map(function ($diagnostic) use ($attachmentId) {
                if ((int) ($diagnostic['result_attachment_id'] ?? 0) === $attachmentId) $diagnostic['result_attachment_id'] = null;
                return $diagnostic;
            }, $record->diagnostics ?? []);
            $record->save();

            return response()->json(['success' => true]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function formatAppointment(ClinicAppointment $a, bool $listOnly = false): array
    {
        $a->loadMissing($this->appointmentRelations($listOnly));
        $user = $a->user;
        $walkin = $a->walkin;
        $pet = $a->pet;
        $vitals = ! $listOnly && $a->relationLoaded('vitals') ? $a->vitals : null;
        $record = ! $listOnly && $a->relationLoaded('record') ? $a->record : null;
        $timeWindow = $a->relationLoaded('timeWindow') ? $a->timeWindow : null;

        $petOwner = $pet?->unregisteredCustomer;
        $ownerName = $user
            ? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
            : ($walkin ? trim("{$walkin->fname} {$walkin->lname}") : ($petOwner ? trim("{$petOwner->first_name} {$petOwner->last_name}") : '—'));

        $ownerAccountDeleted = $this->ownerAccountDeleted($a);
        $contactNumber = $ownerAccountDeleted
            ? '—'
            : ($user?->phone ?? $walkin?->phone ?? $petOwner?->phone ?? '—');
        $ownerEmail = $ownerAccountDeleted ? null : ($user?->email ?? $walkin?->email ?? $petOwner?->email);
        $appointmentTypeLabel = match ($a->appointment_type) {
            'pre_registered' => 'Pre-Registered',
            'scheduled' => 'Scheduled',
            default => 'Walk-in',
        };

        $data = [
            'id' => $a->id,
            'appointment_reference' => $a->appointment_reference,
            'appointment_type' => $a->appointment_type,
            'case_type' => $a->case_type ?? ($a->appointment_type === 'pre_registered' ? 'online_request' : 'consultation'),
            'status' => $a->status,
            'queue_number' => $a->queue_number,
            'appointment_date' => $a->appointment_date?->toDateString(),
            'started_on' => $a->checked_in_at?->toDateString() ?? $a->appointment_date?->toDateString(),
            'time_window' => $timeWindow ? [
                'window_id' => $timeWindow->window_id,
                'window_label' => $timeWindow->displayLabel(),
                'start_time' => $timeWindow->start_time,
                'end_time' => $timeWindow->end_time,
            ] : null,
            'chief_complaint' => $a->chief_complaint,
            'common_concerns' => $a->common_concerns,
            'total_amount' => $a->total_amount,
            'charges' => $a->relationLoaded('charges') ? $a->charges : [],
            'confinement' => $a->relationLoaded('confinement') ? $a->confinement : null,
            'paid' => $a->paid,
            'payment_method' => $a->payment_method,
            'cash_received' => $a->cash_received,
            'change' => $a->cash_received !== null ? round((float) $a->cash_received - (float) $a->total_amount, 2) : null,
            'paid_at' => $a->paid_at?->toIso8601String(),
            'notes' => $a->notes,
            'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            'consultation_started_at' => $a->consultation_started_at?->toIso8601String(),
            'consultation_finished_at' => $a->consultation_finished_at?->toIso8601String(),
            'archived_at' => $a->archived_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'appointment_type_label' => $appointmentTypeLabel,
            'final_status_label' => $this->clinicStatusLabel($a->status),
            'payment_status_label' => $a->paid ? 'Paid' : 'Unpaid',
            'assigned_veterinarian' => null,
            'ownerName' => $ownerName,
            'contactNumber' => $contactNumber,
            'ownerAccountDeleted' => $ownerAccountDeleted,
            'owner_account_deleted' => $ownerAccountDeleted,
            'isWalkin' => $walkin !== null,
            'owner' => [
                'id' => $pet?->user_id ?? $pet?->unregistered_customer_id,
                'recordType' => $pet?->user_id ? 'registered' : 'unregistered',
                'name' => $ownerName,
                'contact_number' => $contactNumber,
                'email' => $ownerEmail,
                'customer_type' => $walkin ? 'Walk-in customer' : 'Registered customer',
                'account_deleted' => $ownerAccountDeleted,
            ],
            'pet' => $pet ? [
                'id' => $pet->pet_id,
                'name' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'gender' => $pet->gender,
                'birthdate' => $pet->birthdate,
                'weight' => $pet->weight,
                'color' => $pet->color,
                'size' => $pet->size,
                'medical_conditions' => $pet->medical_conditions,
                'known_allergies' => null,
            ] : null,
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
            'record' => $this->formatRecord($record, $a->id),
            'activity' => [
                'created_by_name' => null,
                'record_created_at' => $record?->created_at?->toIso8601String(),
                'assessment_completed_by_name' => null,
                'assessment_completed_at' => $a->consultation_finished_at?->toIso8601String(),
                'edited_by_name' => null,
                'record_updated_at' => $record?->updated_at?->toIso8601String(),
                'signed_corrections' => null,
            ],
        ];
        if ($listOnly) {
            $data = array_intersect_key($data, array_flip([
                'id', 'appointment_reference', 'appointment_type', 'case_type', 'status', 'queue_number',
                'appointment_date', 'started_on', 'time_window', 'chief_complaint', 'total_amount', 'paid',
                'ownerName', 'contactNumber', 'owner', 'pet', 'final_status_label',
            ]));
            if ($data['pet']) $data['pet'] = array_intersect_key($data['pet'], array_flip(['id', 'name', 'species', 'breed']));
            $data['list_only'] = true;
        }
        return $data;
    }

    private function appointmentRelations(bool $listOnly = false): array
    {
        $key = $listOnly ? 'list' : 'detail';
        if (isset($this->resolvedAppointmentRelations[$key])) return $this->resolvedAppointmentRelations[$key];
        $this->hasConfinements ??= Schema::hasTable('clinic_confinements');
        $relations = [
            'user',
            'walkin',
            'pet',
        ];

        if (Schema::hasTable('unregistered_customers')) {
            $relations[] = 'pet.unregisteredCustomer';
        }

        if (Schema::hasTable('unregistered_customers')
            && Schema::hasColumn('walkins', 'unregistered_customer_id')) {
            $relations[] = 'walkin.unregisteredCustomer';
        }

        if (Schema::hasTable('time_windows')) {
            $relations[] = 'timeWindow';
        }
        if (! $listOnly && Schema::hasTable('clinic_vitals')) {
            $relations[] = 'vitals';
        }
        if (! $listOnly && Schema::hasTable('clinic_records')) {
            $relations[] = 'record';
            if (Schema::hasTable('clinic_attachments')) {
                $relations[] = 'record.attachments';
            }
        }

        if (! $listOnly && ($this->hasClinicCharges ??= Schema::hasTable('clinic_charges'))) {
            $relations[] = 'charges';
        }
        if (! $listOnly && $this->hasConfinements) {
            $relations[] = 'confinement.caseService.clinicService';
        }

        return $this->resolvedAppointmentRelations[$key] = $relations;
    }

    private function ownerAccountDeleted(ClinicAppointment $appointment): bool
    {
        $userAttributes = $appointment->user?->getAttributes() ?? [];
        if (($userAttributes['account_deleted_at'] ?? null) !== null) {
            return true;
        }

        $unregisteredAttributes = $appointment->walkin?->unregisteredCustomer?->getAttributes() ?? [];

        return ($unregisteredAttributes['account_deleted_at'] ?? null) !== null;
    }

    private function clinicStatusLabel(?string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'Waiting for Arrival',
            'checked_in' => 'Checked In',
            'in_consultation' => 'In Consultation',
            'for_payment' => 'For Payment',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show', 'expired' => 'Expired',
            default => 'Data is currently unavailable',
        };
    }

    private function formatRecord(?ClinicRecord $record, int $appointmentId): ?array
    {
        if (! $record) {
            return null;
        }

        $record->loadMissing('attachments');

        return [
            'id' => $record->id,
            'chief_complaint' => $record->chief_complaint,
            'diagnosis' => $record->diagnosis,
            'findings' => $record->findings,
            'treatment_given' => $record->treatment_given,
            'follow_up_date' => $record->follow_up_date?->toDateString(),
            'follow_up_notes' => $record->follow_up_notes,
            'vet_notes' => $record->vet_notes,
            ...$record->clinicalDetails(),
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'attachments' => $record->attachments
                ->map(fn (ClinicAttachment $attachment) => $this->formatAttachment($attachment, $appointmentId))
                ->values(),
        ];
    }

    private function formatAttachment(ClinicAttachment $attachment, int $appointmentId): array
    {
        return [
            'id' => $attachment->id,
            'clinic_case_service_id' => $attachment->clinic_case_service_id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size_bytes' => $attachment->file_size_bytes,
            'label' => $attachment->label,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'download_endpoint' => "/api/admin/clinic-appointments/{$appointmentId}/attachments/{$attachment->id}/download",
        ];
    }

    private function findScopedAttachment(int $appointmentId, int $attachmentId): ClinicAttachment
    {
        $appointmentExists = ClinicAppointment::whereKey($appointmentId)->exists();

        if (! $appointmentExists) {
            $this->attachmentNotFound();
        }

        $attachment = ClinicAttachment::query()
            ->whereKey($attachmentId)
            ->whereHas('record', fn ($query) => $query->where('clinic_appointment_id', $appointmentId))
            ->first();

        if (! $attachment) {
            $this->attachmentNotFound();
        }

        return $attachment;
    }

    private function attachmentDisk(string $path): ?string
    {
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    private function safeDownloadName(ClinicAttachment $attachment): string
    {
        $fileName = basename(str_replace('\\', '/', $attachment->file_name));
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?? '';

        return $fileName !== '' ? $fileName : "clinic-attachment-{$attachment->id}";
    }

    private function safeMimeType(?string $mimeType): string
    {
        return $mimeType && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mimeType)
            ? $mimeType
            : 'application/octet-stream';
    }

    private function attachmentNotFound(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Attachment not found.',
        ], 404));
    }

    private function assertClinicalContentEditable(
        ClinicAppointment $appointment,
    ): void {
        if ($appointment->clinicalContentEditableFor(request()->user())) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Start the consultation before modifying clinical records. For Payment corrections are limited to administrators.',
        ], 409));
    }
}
