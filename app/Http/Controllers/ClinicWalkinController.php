<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClinicPreRegistrationRequest;
use App\Http\Requests\StoreClinicWalkinRequest;
use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use App\Models\Notification;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Models\Walkin;
use App\Rules\ValidBreedCoat;
use App\Services\AvailabilityTimeWindowService;
use App\Services\ClinicAppointmentSequence;
use App\Services\CustomerPreRegistrationAccessService;
use App\Support\ClinicConcerns;
use App\Support\PetWeightSize;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClinicWalkinController extends Controller
{
    public function timeslots(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    ) {
        $today = now()->toDateString();
        $lastAvailableDate = now()->addDays(2)->toDateString();
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

            $access = $preRegistrationAccess->forUser((int) $user->user_id);
            if (! $access['allowed']) {
                return response()->json([
                    'success' => false,
                    'code' => 'ongoing_pre_registration',
                    'message' => $access['message'],
                    'ongoing' => $access['ongoing'],
                ], 409);
            }

            $appointmentDate = app(\App\Services\RegistrationServiceDay::class)->resolve('clinic');

            $pet = Pet::whereKey($data['pet_id'])
                ->where('user_id', $user->user_id)
                ->where('is_archived', false)
                ->firstOrFail();

            $duplicate = ClinicAppointment::query()
                ->whereDate('appointment_date', $appointmentDate)
                ->where('user_id', $user->user_id)
                ->where('pet_id', $pet->pet_id)
                ->whereNotIn('status', ['cancelled', 'no_show', 'expired'])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => "{$pet->pet_name} already has a clinic visit registered on this date.",
                ], 422);
            }

            $sequence = $clinicSequence->reserve($appointmentDate, false);
            $reference = $sequence['appointment_reference'];

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $reference,
                'appointment_type' => 'pre_registered',
                'case_type' => 'online_request',
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'appointment_date' => $appointmentDate,
                'window_id' => null,
                'user_id' => $user->user_id,
                'walkin_id' => null,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => ClinicConcerns::summary($data['common_concerns'], $data['chief_complaint'] ?? null),
                'common_concerns' => $data['common_concerns'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => null,
            ]);

            $ownerName = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            Notification::createForClinic(
                $appointment,
                Notification::TYPE_CLINIC_BOOKED,
                "Clinic online registration {$reference} was submitted by {$ownerName} for {$pet->pet_name}.",
            );

            return response()->json([
                'success' => true,
                'message' => 'Your online registration has been received. Your queue position will be assigned when you check in at the clinic.',
                'appointment' => [
                    'appointment_id' => $appointment->id,
                    'appointment_reference' => $appointment->appointment_reference,
                    'appointment_type' => $appointment->appointment_type,
                    'appointment_date' => $appointment->appointment_date->toDateString(),
                    'time_window' => null,
                    'queue_number' => null,
                    'created_at' => $appointment->created_at->toIso8601String(),
                    'checked_in_at' => null,
                    'status' => $appointment->status,
                    'chief_complaint' => $appointment->chief_complaint,
                    'common_concerns' => $appointment->common_concerns,
                    'owner' => [
                        'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ],
                    'pet' => [
                        'pet_id' => $pet->pet_id,
                        'name' => $pet->pet_name,
                        'species' => $pet->species,
                        'breed' => $pet->breed,
                    ],
                ],
            ], 201);
        });
    }

    public function store(
        StoreClinicWalkinRequest $request,
        ClinicAppointmentSequence $clinicSequence,
    ) {
        app(\App\Services\PreRegistrationExpiry::class)->expire();
        return app(\App\Services\DailyPetQueue::class)->runForDate(now()->toDateString(), function () use ($request, $clinicSequence) {
            app(\App\Services\OperationalCapacity::class)->assertCanAccept('clinic', 1);
            $data = $request->validated();
            $data = PetWeightSize::withComputedSize($data);

            [$user, $unregisteredCustomer, $owner] = $this->resolveWalkInOwner($data);

            $walkin = Walkin::create([
                'fname' => $owner['fname'],
                'lname' => $owner['lname'],
                'mname' => $owner['mname'],
                'email' => $owner['email'],
                'phone' => $owner['phone'],
                'sedation_consent' => false,
                'terms_agreed' => $data['terms_agreed'],
                'user_id' => $user?->user_id,
                ...($unregisteredCustomer ? ['unregistered_customer_id' => $unregisteredCustomer->id] : []),
                'appointment_type' => 'clinic',
                'chief_complaint' => ClinicConcerns::summary($data['common_concerns'], $data['chief_complaint'] ?? null),
            ]);

            $pet = $this->findOrCreatePet($user, $unregisteredCustomer, $data);

            $existing = ClinicAppointment::query()->where('pet_id', $pet->pet_id)->blocking()->lockForUpdate()->first();
            if ($existing) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'success' => false,
                    'code' => $existing->status === 'waiting_to_arrive' ? 'pre_registration_exists' : 'active_case_exists',
                    'message' => "{$pet->pet_name} already has an ongoing clinic visit. Check the current case before registering another visit.",
                ], 409));
            }

            $sequence = $clinicSequence->reserve(now()->toDateString(), true);
            $reference = $sequence['appointment_reference'];

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $reference,
                'appointment_type' => 'walk_in',
                'case_type' => 'consultation',
                'status' => 'checked_in',
                'queue_number' => $sequence['queue_number'],
                'appointment_date' => now()->toDateString(),
                'user_id' => $user?->user_id,
                'walkin_id' => $walkin->id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $walkin->chief_complaint,
                'common_concerns' => $data['common_concerns'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => now(),
                'consultation_started_at' => null,
            ]);

            $ownerName = trim("{$walkin->fname} {$walkin->lname}");
            Notification::createForClinic(
                $appointment,
                Notification::TYPE_CLINIC_WALK_IN,
                "Clinic walk-in {$reference} was registered for {$ownerName} and {$pet->pet_name}.",
            );

            return response()->json([
                'success' => true,
                'appointment_reference' => $reference,
                'queue_number' => $appointment->queue_number,
                'appointment_id' => $appointment->id,
                'appointment_date' => $appointment->appointment_date->toDateString(),
                'status' => $appointment->status,
                'checked_in_at' => $appointment->checked_in_at->toIso8601String(),
                'owner' => [
                    'name' => trim("{$walkin->fname} {$walkin->lname}"),
                    'email' => $walkin->email,
                    'phone' => $walkin->phone,
                ],
                'pet' => [
                    'pet_id' => $pet->pet_id,
                    'name' => $pet->pet_name,
                    'species' => $pet->species,
                    'breed' => $pet->breed,
                ],
                'chief_complaint' => $walkin->chief_complaint,
                'common_concerns' => $appointment->common_concerns,
                'returning_customer' => $user !== null || $unregisteredCustomer !== null,
            ], 201);
        });
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
        } elseif (! empty($data['email'])) {
            // Preserve the existing clinic walk-in behavior for manually entered
            // owners whose email already belongs to a registered customer.
            $user = User::registeredCustomer()->where('email', $data['email'])->first();
        }

        $owner = $user
            ? [
                'fname' => $user->first_name,
                'lname' => $user->last_name,
                'mname' => null,
                'email' => $user->email,
                'phone' => $user->phone ?? $data['phone'],
            ]
            : ($unregisteredCustomer
                ? [
                    'fname' => $unregisteredCustomer->first_name,
                    'lname' => $unregisteredCustomer->last_name,
                    'mname' => $unregisteredCustomer->middle_name,
                    'email' => $unregisteredCustomer->email,
                    'phone' => $unregisteredCustomer->phone,
                ]
                : [
                    'fname' => $data['fname'],
                    'lname' => $data['lname'],
                    'mname' => $data['mname'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                ]);

        return [$user, $unregisteredCustomer, $owner];
    }

    private function findOrCreatePet(
        ?User $user,
        ?UnregisteredCustomer $unregisteredCustomer,
        array $data,
    ): Pet {
        $data['pet_name'] = Pet::normalizeName($data['pet_name']);
        $hasExistingOwner = $user !== null || $unregisteredCustomer !== null;
        $ownerPetQuery = Pet::query()
            ->when(
                $user !== null,
                fn ($query) => $query->where('user_id', $user->user_id),
                fn ($query) => $query->where('unregistered_customer_id', $unregisteredCustomer?->id),
            )
            ->where('is_archived', false);

        if (! empty($data['pet_id'])) {
            $existing = (clone $ownerPetQuery)
                ->where('pet_id', $data['pet_id'])
                ->first();

            if (! $hasExistingOwner || ! $existing) {
                throw ValidationException::withMessages([
                    'pet_id' => ['The selected pet does not belong to this customer.'],
                ]);
            }

            return $this->updateWalkInPetDetails($existing, $data);
        }

        if ($hasExistingOwner) {
            $existing = (clone $ownerPetQuery)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($data['pet_name'])])
                ->first();

            if ($existing) {
                return $this->updateWalkInPetDetails($existing, $data);
            }
        }

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
            'size' => $data['size'] ?? null,
            'clinic_verified_fields' => ! empty($data['size']) ? ['size'] : null,
            'color' => $data['color'] ?? null,
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }

    private function updateWalkInPetDetails(Pet $pet, array $data): Pet
    {
        $data = PetWeightSize::withComputedSize([...$data, 'species' => $pet->species, 'weight' => $data['weight'] ?? $pet->weight]);
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
            'color' => $data['color'] ?? $pet->color,
            'medical_conditions' => $data['medical_conditions'] ?? $pet->medical_conditions,
        ]);

        if (! empty($data['size'])) {
            $pet->confirmClinicSize($data['size']);
        }

        return $pet;
    }
}
