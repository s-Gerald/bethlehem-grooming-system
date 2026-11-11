<?php

namespace App\Http\Controllers;

use App\Support\PetProfileSnapshot;
use App\Support\AccountEmail;

use App\Http\Requests\StoreClinicPreRegistrationRequest;
use App\Http\Requests\StoreClinicWalkinRequest;
use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use App\Models\ClinicVisitGroup;
use App\Models\Notification;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Models\Walkin;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetBirthdate;
use App\Services\AvailabilityTimeWindowService;
use App\Services\ClinicAppointmentSequence;
use App\Services\CustomerPreRegistrationAccessService;
use App\Support\ClinicConcerns;
use App\Support\PetWeightSize;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicWalkinController extends Controller
{
    public function customerRegistration(Request $request, int $id)
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire($request->user()->user_id);
        $visit = ClinicAppointment::query()->whereKey($id)
            ->where('user_id', $request->user()->user_id)
            ->where('appointment_type', 'pre_registered')
            ->first();

        if (! $visit) {
            return response()->json(['success' => false, 'message' => 'Visit not found.'], 404);
        }

        $patients = $this->registrationPatients($visit)->load('pet:pet_id,pet_name,species,breed');

        $visit = $patients->firstWhere('id', $id);
        return response()->json(['success' => true, 'visit' => $this->registrationDetails($visit) + [
            'clinic_visit_group_id' => $visit->clinic_visit_group_id,
            'patients' => $patients->map(fn (ClinicAppointment $patient) => $this->registrationDetails($patient))->values(),
            'can_cancel' => $this->canCancelRegistration($patients),
        ]]);
    }

    private function registrationDetails(ClinicAppointment $visit): array
    {
        return [
            'id' => $visit->id, 'reference' => $visit->appointment_reference,
            'status' => $visit->status, 'service_day' => $visit->appointment_date?->toDateString(),
            'created_at' => $visit->created_at?->toIso8601String(),
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'queue_number' => $visit->queue_number,
            'common_concerns' => $visit->common_concerns,
            'chief_complaint' => $visit->chief_complaint,
            'pet' => in_array($visit->status, ClinicAppointment::TERMINAL_STATUSES, true)
                ? PetProfileSnapshot::pet($visit->pet_profile_snapshot, $visit->pet_id)?->only(['pet_id', 'pet_name', 'species', 'breed'])
                : $visit->pet,
        ];
    }

    private function registrationPatients(ClinicAppointment $visit, bool $lock = false): \Illuminate\Database\Eloquent\Collection
    {
        if (! $visit->clinic_visit_group_id) {
            return new \Illuminate\Database\Eloquent\Collection([
                $lock ? ClinicAppointment::query()->lockForUpdate()->findOrFail($visit->id) : $visit,
            ]);
        }

        $activity = ['record as has_record', 'vitals as has_vitals', 'charges as has_charges'];
        if (\Illuminate\Support\Facades\Schema::hasTable('clinic_confinements')) {
            $activity['confinement as has_confinement'] = fn ($episode) => $episode->whereNull('discharged_at');
        }
        return ClinicAppointment::query()->where('clinic_visit_group_id', $visit->clinic_visit_group_id)
            ->where('user_id', $visit->user_id)->withExists($activity)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
    }

    private function canCancelRegistration(Collection $patients): bool
    {
        $remaining = $patients->where('status', '!=', 'cancelled');
        $grouped = $patients->first()?->clinic_visit_group_id !== null;
        if ($grouped && \App\Models\GatewayPayment::clinicPaymentPending($patients->first())) {
            return false;
        }

        return $remaining->isNotEmpty() && $remaining->every(fn (ClinicAppointment $visit) => $visit->canCustomerCancel(
            $grouped ? ($visit->has_record || $visit->has_vitals || $visit->has_charges || $visit->has_confinement) : null,
        ));
    }

    public function cancelCustomerRegistration(Request $request, int $id)
    {
        app(\App\Services\PreRegistrationExpiry::class)->expire($request->user()->user_id);

        return DB::transaction(function () use ($request, $id) {
            // Match intake/expiry lock order before rechecking persisted eligibility.
            ClinicSetting::current(lockForUpdate: true);
            $visit = ClinicAppointment::query()->whereKey($id)
                ->where('user_id', $request->user()->user_id)->firstOrFail();

            $patients = $this->registrationPatients($visit, lock: true);
            if (! $this->canCancelRegistration($patients)) {
                return response()->json(['success' => false, 'code' => 'clinic_registration_not_cancellable',
                    'message' => 'This clinic registration can no longer be cancelled online. Refresh to see its current status.'], 409);
            }

            foreach ($patients->where('status', '!=', 'cancelled') as $patient) {
                $patient->update(['status' => 'cancelled']);
            }
            Notification::createForClinic($visit, Notification::TYPE_CLINIC_CANCELLED,
                "Clinic registration {$visit->appointment_reference} was cancelled by the customer.");

            return response()->json(['success' => true, 'message' => 'Clinic registration cancelled.']);
        }, 5);
    }

    public function timeslots(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    ) {
        $today = now('Asia/Manila')->toDateString();
        $lastAvailableDate = now('Asia/Manila')->addDay()->toDateString();
        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today,
                'before_or_equal:'.$lastAvailableDate,
            ],
        ]);
        $appointmentDate = $data['date'];
        $settings = ClinicSetting::current();
        $availability = $settings->serviceAvailability('clinic');

        return response()->json([
            'success' => true,
            'date' => $appointmentDate,
            'availability' => $availability,
            'windows' => $timeWindows->preferredWindows($settings, 'clinic', $appointmentDate),
        ]);
    }

    public function preRegister(
        StoreClinicPreRegistrationRequest $request,
        ClinicAppointmentSequence $clinicSequence,
        CustomerPreRegistrationAccessService $preRegistrationAccess,
    ) {
        return app(\App\Services\DailyPetQueue::class)->runForDate(now('Asia/Manila')->toDateString(), function () use (
            $request,
            $clinicSequence,
            $preRegistrationAccess,
        ) {
            $data = $request->validated();
            $user = $request->user();
            $lockedUser = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
            \App\Services\CustomerPhoneVerificationService::assertPreRegistration($lockedUser);

            $access = $preRegistrationAccess->forUser((int) $user->user_id)['services']['clinic'];
            if (! $access['allowed']) {
                $conflict = isset($data['patients']) ? ClinicAppointment::with('pet:pet_id,pet_name')->blocking()
                    ->where('user_id', $user->user_id)->whereIn('pet_id', array_column($data['patients'], 'pet_id'))->first() : null;
                $message = $conflict?->pet
                    ? "{$conflict->pet->pet_name} already has an active clinic visit. Remove this pet or resolve the existing visit before continuing."
                    : $access['message'];
                return response()->json([
                    'success' => false,
                    'code' => 'ongoing_pre_registration',
                    'message' => $message,
                    'ongoing' => $access['ongoing'],
                ], 409);
            }

            $days = app(\App\Services\RegistrationServiceDay::class);
            $appointmentDate = $days->assertReviewedDay('clinic', $data['service_day'] ?? null);

            $patients = $data['patients'] ?? [$data];
            $pets = Pet::query()->whereIn('pet_id', array_column($patients, 'pet_id'))
                ->where('user_id', $user->user_id)->where('is_archived', false)
                ->orderBy('pet_id')->lockForUpdate()->get()->keyBy('pet_id');
            foreach ($patients as $index => $patient) {
                $pet = $pets->get($patient['pet_id']);
                if (! $pet || $pet->is_deceased) {
                    $field = isset($data['patients']) ? "patients.{$index}.pet_id" : 'pet_id';
                    throw ValidationException::withMessages([$field => 'Select an active pet from your account.']);
                }
            }

            $policy = app(\App\Services\PetServiceSessionPolicy::class);
            $policy->assertNoSameService($pets->modelKeys(), 'clinic');
            $policy->enforce($pets->modelKeys(), 'clinic');

            $group = ClinicVisitGroup::create([
                'user_id' => $user->user_id,
                'source' => 'pre_registered',
                'service_day' => $appointmentDate,
            ]);
            $appointments = $this->createPatients($group, $patients, $pets, $clinicSequence, [
                'registration_closes_at' => $days->closingDeadline('clinic', $appointmentDate),
                'window_id' => null,
            ]);
            $owner = [
                'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'email' => $user->email,
                'phone' => $user->phone,
            ];
            $session = $this->registrationSession($group, $appointments, $owner);
            $first = $appointments->first();
            $names = ClinicVisitGroup::patientNames($appointments->pluck('pet'));
            Notification::createForClinic($first, Notification::TYPE_CLINIC_BOOKED,
                "Clinic queue registration {$first->appointment_reference} was submitted by {$owner['name']} for {$names}.");

            return response()->json([
                'success' => true,
                'message' => 'Your queue registration has been received. Your queue number will be assigned when you check in at Bethlehem.',
                'session' => $session,
                'appointment' => $session['patients'][0] + ['owner' => $owner],
            ], 201);
        });
    }

    public function store(
        StoreClinicWalkinRequest $request,
        ClinicAppointmentSequence $clinicSequence,
    ) {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        $day = now('Asia/Manila')->toDateString();

        return app(\App\Services\DailyPetQueue::class)->runForDate($day, function () use ($request, $clinicSequence, $day) {
            $data = $request->validated();
            $patients = $request->patients();
            app(\App\Services\OperationalCapacity::class)->assertCanAccept('clinic', count($patients));

            [$user, $unregisteredCustomer, $owner] = $this->resolveWalkInOwner($data);
            $profiles = $this->walkInProfiles($user, $unregisteredCustomer, $patients);
            $pets = collect();
            foreach ($patients as $index => &$patient) {
                try {
                    $pet = $this->findOrCreatePet($user, $unregisteredCustomer, $patient, $profiles);
                    if ($pets->has($pet->pet_id)) {
                        throw ValidationException::withMessages(['pet_id' => 'Select each pet only once.']);
                    }
                    $pets->put($pet->pet_id, $pet);
                    if ($user || $unregisteredCustomer) {
                        $profiles->put($pet->pet_id, $pet);
                    }
                    $patient['pet_id'] = $pet->pet_id;
                } catch (ValidationException $exception) {
                    if (! isset($data['patients'])) {
                        throw $exception;
                    }
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["patients.{$index}.{$field}"] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }
            unset($patient);

            $existing = ClinicAppointment::query()->whereIn('pet_id', $pets->keys())->blocking()
                ->orderBy('id')->lockForUpdate()->first();
            if ($existing) {
                $name = $pets->get($existing->pet_id)->pet_name;
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => $existing->status === 'waiting_to_arrive' ? 'pre_registration_exists' : 'active_case_exists',
                    'message' => "{$name} already has an ongoing clinic visit. Check the current case before registering another visit.",
                ], 409));
            }

            app(\App\Services\PetServiceSessionPolicy::class)->enforce($pets->keys()->all(), 'clinic', true);

            $walkin = Walkin::create([
                'fname' => $owner['fname'],
                'lname' => $owner['lname'],
                'email' => $owner['email'],
                'phone' => $owner['phone'],
                'sedation_consent' => false,
                'terms_agreed' => $data['terms_agreed'],
                'user_id' => $user?->user_id,
                ...($unregisteredCustomer ? ['unregistered_customer_id' => $unregisteredCustomer->id] : []),
                'appointment_type' => 'clinic',
            ]);

            $group = ClinicVisitGroup::create([
                'user_id' => $user?->user_id,
                'walkin_id' => $walkin->id,
                'source' => 'walk_in',
                'service_day' => $day,
            ]);
            $appointments = $this->createPatients($group, $patients, $pets, $clinicSequence);
            $ownerName = trim("{$walkin->fname} {$walkin->lname}");
            $owner = ['name' => $ownerName, 'email' => $walkin->email, 'phone' => $walkin->phone];
            $session = $this->registrationSession($group, $appointments, $owner);
            $first = $appointments->first();
            $names = ClinicVisitGroup::patientNames($appointments->pluck('pet'));
            Notification::createForClinic($first, Notification::TYPE_CLINIC_WALK_IN,
                "Clinic walk-in {$first->appointment_reference} was registered for {$ownerName} and {$names}.");

            return response()->json([
                'success' => true,
                'session' => $session,
                'owner' => $owner,
                'returning_customer' => $user !== null || $unregisteredCustomer !== null,
                ...$session['patients'][0],
            ], 201);
        });
    }

    private function createPatients(
        ClinicVisitGroup $group,
        array $patients,
        Collection $pets,
        ClinicAppointmentSequence $sequence,
        array $extra = [],
    ): Collection {
        $walkin = $group->source === 'walk_in';
        $day = $group->service_day->toDateString();
        $numbers = $sequence->reserveBatch($day, $walkin, count($patients));
        $checkIn = $walkin ? now() : null;

        return collect($patients)->map(function (array $patient, int $index) use ($group, $pets, $numbers, $walkin, $day, $checkIn, $extra) {
            $pet = $pets->get($patient['pet_id']);
            return $group->appointments()->create([
                ...$extra,
                ...$numbers[$index],
                'appointment_type' => $group->source,
                'pet_profile_snapshot' => PetProfileSnapshot::registration($pet, intake: $walkin),
                'case_type' => $walkin ? 'consultation' : 'online_request',
                'status' => $walkin ? 'checked_in' : 'waiting_to_arrive',
                'appointment_date' => $day,
                'user_id' => $group->user_id,
                'walkin_id' => $group->walkin_id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => ClinicConcerns::summary($patient['common_concerns'], $patient['chief_complaint'] ?? null),
                'common_concerns' => $patient['common_concerns'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => $checkIn,
            ])->setRelation('pet', $pet);
        });
    }

    private function registrationSession(ClinicVisitGroup $group, Collection $appointments, array $owner): array
    {
        return [
            'id' => $group->id,
            'service_day' => $group->service_day->toDateString(),
            'owner' => $owner,
            'patients' => $appointments->map(function (ClinicAppointment $appointment) {
                $pet = $appointment->pet;
                return [
                    'appointment_id' => $appointment->id,
                    'appointment_reference' => $appointment->appointment_reference,
                    'appointment_type' => $appointment->appointment_type,
                    'appointment_date' => $appointment->appointment_date->toDateString(),
                    'time_window' => null,
                    'queue_number' => $appointment->queue_number,
                    'created_at' => $appointment->created_at?->toIso8601String(),
                    'checked_in_at' => $appointment->checked_in_at?->toIso8601String(),
                    'status' => $appointment->status,
                    'chief_complaint' => $appointment->chief_complaint,
                    'common_concerns' => $appointment->common_concerns,
                    'pet' => [
                        'pet_id' => $pet?->pet_id,
                        'name' => $pet?->pet_name,
                        'species' => $pet?->species,
                        'breed' => $pet?->breed,
                    ],
                ];
            })->values(),
        ];
    }

    private function resolveWalkInOwner(array $data): array
    {
        $recordType = $data['owner_record_type'] ?? 'new';
        $user = null;
        $unregisteredCustomer = null;

        if ($recordType === 'registered') {
            $user = User::query()
                ->where('user_id', $data['customer_user_id'] ?? null)
                ->registeredCustomer()
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'customer_user_id' => ['The selected customer is no longer available.'],
                ]);
            }
        } elseif ($recordType === 'unregistered') {
            $unregisteredCustomer = UnregisteredCustomer::query()
                ->availableCustomer()
                ->where('id', $data['unregistered_customer_id'] ?? null)
                ->where('is_archived', false)
                ->first();

            if (! $unregisteredCustomer) {
                throw ValidationException::withMessages([
                    'unregistered_customer_id' => ['The selected unregistered customer is no longer available.'],
                ]);
            }
        } elseif (! empty($data['email']) && User::registeredCustomer()->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already in use. Select the existing customer.']);
        }

        if ($user && $user->phone === null) {
            $data['phone'] = \App\Support\PhilippineMobile::normalize($data['phone']);
            validator(['phone' => $data['phone']], ['phone' => \App\Support\PhilippineMobile::rules()])->validate();
        }

        $owner = $user
            ? [
                'fname' => $user->first_name,
                'lname' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone ?? $data['phone'],
            ]
            : ($unregisteredCustomer
                ? [
                    'fname' => $unregisteredCustomer->first_name,
                    'lname' => $unregisteredCustomer->last_name,
                    'email' => $unregisteredCustomer->email,
                    'phone' => $unregisteredCustomer->phone,
                ]
                : [
                    'fname' => $data['fname'],
                    'lname' => $data['lname'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                ]);

        return [$user, $unregisteredCustomer, $owner];
    }

    private function walkInProfiles(?User $user, ?UnregisteredCustomer $owner, array $patients): Collection
    {
        if (! $user && ! $owner) {
            return collect();
        }

        $ids = array_column($patients, 'pet_id');
        $names = collect($patients)->filter(fn ($patient) => empty($patient['pet_id']))
            ->map(fn ($patient) => strtolower(Pet::normalizeName($patient['pet_name'])))->all();

        return Pet::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->user_id),
                fn ($query) => $query->where('unregistered_customer_id', $owner->id))
            ->where('is_archived', false)
            ->where(fn ($query) => $query->whereIn('pet_id', $ids)
                ->orWhereIn(DB::raw('LOWER(pet_name)'), $names))
            ->orderBy('pet_id')->lockForUpdate()->get()->keyBy('pet_id');
    }

    private function findOrCreatePet(
        ?User $user,
        ?UnregisteredCustomer $unregisteredCustomer,
        array $data,
        Collection $profiles,
    ): Pet {
        $data['pet_name'] = Pet::normalizeName($data['pet_name']);
        $id = $data['pet_id'] ?? null;
        $existing = $id !== null
            ? $profiles->get($id)
            : $profiles->first(fn (Pet $pet) => strtolower($pet->pet_name) === strtolower($data['pet_name']));

        if ($id !== null && ! $existing) {
            throw ValidationException::withMessages(['pet_id' => 'The selected pet does not belong to this customer.']);
        }
        if ($existing?->is_deceased) {
            throw ValidationException::withMessages(['pet_id' => "{$existing->pet_name} is no longer an active pet."]);
        }
        if ($existing) {
            return $this->updateWalkInPetDetails($existing, $data);
        }

        $data = PetWeightSize::withComputedSize($data);

        return Pet::create([
            'user_id' => $user?->user_id,
            ...($unregisteredCustomer ? ['unregistered_customer_id' => $unregisteredCustomer->id] : []),
            'pet_name' => $data['pet_name'],
            'species' => $data['species'],
            'breed' => $data['breed'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'is_neutered' => $data['is_neutered'] ?? false,
            'neutered_date' => ($data['is_neutered'] ?? false)
                ? ($data['neutered_date'] ?? null)
                : null,
            'fur_type' => ValidBreedCoat::validatedCoat($data),
            'weight' => $data['weight'] ?? null,
            ...array_intersect_key($data, ['body_condition' => true]),
            'size' => $data['size'] ?? null,
            'clinic_verified_fields' => ! empty($data['size']) ? ['size'] : null,
            'color' => ValidPetColor::validatedColor($data),
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }

    private function updateWalkInPetDetails(Pet $pet, array $data): Pet
    {
        \Illuminate\Support\Facades\Validator::make([...$data, 'species' => $pet->species], [
            'birthdate' => ['nullable', new ValidPetBirthdate($pet->birthdate)],
            'weight' => ['nullable', new \App\Rules\ValidPetWeight(existingWeight: $pet->weight)],
        ])->validate();
        $data['color'] ??= $pet->color;
        $data = PetWeightSize::withComputedSizeForUpdate([...$data, 'species' => $pet->species], $pet);
        $pet->update([
            'breed' => $data['breed'] ?? $pet->breed,
            'gender' => $data['gender'] ?? $pet->gender,
            'birthdate' => $data['birthdate'] ?? $pet->birthdate,
            'is_neutered' => $data['is_neutered'] ?? $pet->is_neutered,
            'neutered_date' => array_key_exists('neutered_date', $data)
                ? $data['neutered_date']
                : $pet->neutered_date,
            'fur_type' => ValidBreedCoat::validatedCoat($data, $pet),
            'weight' => $data['weight'] ?? $pet->weight,
            ...array_intersect_key($data, ['body_condition' => true]),
            'size' => $data['size'] ?? $pet->size,
            'clinic_verified_fields' => ! empty($data['size'])
                ? array_values(array_unique([...($pet->clinic_verified_fields ?? []), 'size']))
                : $pet->clinic_verified_fields,
            'color' => ValidPetColor::validatedColor($data, $pet),
            'medical_conditions' => $data['medical_conditions'] ?? $pet->medical_conditions,
        ], ['verify_body_condition' => array_key_exists('body_condition', $data)]);

        return $pet;
    }
}
