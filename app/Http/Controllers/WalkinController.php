<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWalkinRequest;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Pet;
use App\Models\Service;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Models\Walkin;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Services\CustomerIdentityService;
use App\Services\DailyPetQueue;
use App\Services\GroomingIntakeSequence;
use App\Services\GroomingServicePriceResolver;
use App\Support\PetWeightSize;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkinController extends Controller
{
    public function __construct(
        private readonly GroomingServicePriceResolver $servicePrices,
        private readonly CustomerIdentityService $customerIdentity,
    ) {}

    public function store(StoreWalkinRequest $request)
    {
        $queueDate = now()->toDateString();

        try {
            return app(DailyPetQueue::class)->runForDate($queueDate, function () use ($request, $queueDate) {
                $data = $request->validated();
                $submissionKey = $data['submission_key'] ?? null;
                $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                if ($submissionKey) {
                    $previous = DB::table('grooming_walkin_submissions')->where('submission_key', $submissionKey)->first();
                    if ($previous) {
                        if ((int) $previous->actor_user_id !== (int) $request->user()->user_id || ! hash_equals($previous->payload_hash, $payloadHash)) {
                            throw ValidationException::withMessages(['submission_key' => 'This submission has already been used. Start a new walk-in for another visit.']);
                        }

                        return response()->json(json_decode($previous->response, true, flags: JSON_THROW_ON_ERROR), 201);
                    }
                }
                $data['pets'] = app(\App\Services\PetGroomerSelection::class)->validate($data['pets'], true);
                app(\App\Services\OperationalCapacity::class)->assertCanAccept('grooming', count($data['pets']));
                $data['pets'] = array_map(
                    fn (array $pet) => PetWeightSize::withComputedSize($pet),
                    $data['pets'],
                );

                [$user, $unregisteredCustomer, $owner] = $this->resolveOwner($data);

                $resolvedPets = $this->resolveAllPets($data['pets'], $user, $unregisteredCustomer);
                $totalAmount = array_sum(array_map(
                    fn ($p) => array_sum(array_column($p['services'], 'price')),
                    $resolvedPets,
                ));

                // Create the walk-in record (owner info + consent)
                $walkin = Walkin::create([
                    'fname' => $owner['fname'],
                    'lname' => $owner['lname'],
                    'mname' => $owner['mname'],
                    'email' => $owner['email'],
                    'phone' => $owner['phone'],
                    'sedation_consent' => $data['sedation_consent'],
                    'terms_agreed' => $data['terms_agreed'],
                    'user_id' => $user?->user_id,
                    ...($unregisteredCustomer ? ['unregistered_customer_id' => $unregisteredCustomer->id] : []),
                    'appointment_type' => 'grooming',
                ]);

                // Scheduled and walk-in owners share the same daily queue.
                $sequence = app(GroomingIntakeSequence::class)->reserve($queueDate, count($resolvedPets));
                $queueNumber = $sequence['queue_number'];
                $bookingReference = $sequence['booking_reference'];

                // Create the booking — walk-in customers are already on-site so they
                // enter the queue immediately (checked_in) rather than waiting_to_arrive
                $booking = Booking::create([
                    'booking_reference' => $bookingReference,
                    'user_id' => $user?->user_id,
                    'walkin_id' => $walkin->id,
                    'booking_date' => $queueDate,
                    'number_of_pets' => count($resolvedPets),
                    'booking_type' => 'walk_in',
                    'status' => 'checked_in',
                    'queue_number' => $queueNumber,
                    'total_amount' => $totalAmount,
                    'dropped_off_at' => now(),
                ]);

                $signedConsent = \App\Services\GroomingConsentCapture::capture($booking, $data);

                // For each pet: find or create a pet record, then attach services
                $petsResponse = [];
                $hasServiceSnapshots = \Illuminate\Support\Facades\Schema::hasColumn('booking_services', 'service_name_snapshot');
                $groomers = \App\Models\PetGroomer::whereIn('id', array_column($data['pets'], 'groomer_id'))->get()->keyBy('id');
                foreach ($resolvedPets as $item) {
                    $pet = $item['pet'];

                    $bookingPet = BookingPet::create([
                        'booking_id' => $booking->booking_id,
                        'pet_id' => $pet->pet_id,
                        'registered_size' => $item['petData']['size'] ?? null,
                        'confirmed_size' => $item['size'],
                        'special_instructions' => trim($item['petData']['special_instructions'] ?? '') ?: null,
                        'haircut_requested' => (bool) ($item['petData']['haircut_requested'] ?? false),
                        'groomer_id' => $item['petData']['groomer_id'],
                        'grooming_preference' => $item['petData']['grooming_preference'] ?? null,
                    ]);


                    foreach ($item['services'] as $svc) {
                        BookingService::create([
                            'booking_id' => $booking->booking_id,
                            'booking_pet_id' => $bookingPet->booking_pet_id,
                            'service_id' => $svc['service_id'],
                            ...($hasServiceSnapshots ? [
                                'service_name_snapshot' => $svc['name'],
                                'service_kind_snapshot' => $svc['kind'],
                            ] : []),
                            'price_at_booking' => $svc['price'],
                            'price_min_at_booking' => $svc['price_min'],
                            'price_max_at_booking' => $svc['price_max'],
                        ]);
                    }

                    $petsResponse[] = [
                        'booking_pet_id' => $bookingPet->booking_pet_id,
                        'groomer_id' => $bookingPet->groomer_id,
                        'pet_groomer' => $groomers->get($bookingPet->groomer_id),
                        'pet_id' => $pet->pet_id,
                        'pet_name' => $item['petData']['pet_name'],
                        'haircut_requested' => (bool) $bookingPet->haircut_requested,
                        'species' => $item['petData']['species'],
                        'size' => $item['size'],
                        'grooming_preference' => $bookingPet->grooming_preference, 'grooming_preference_label' => $bookingPet->grooming_preference_label,
                        'services' => array_map(fn ($s) => [
                            'name' => $s['name'],
                            'price' => $s['price'],
                            'price_min' => $s['price_min'],
                            'price_max' => $s['price_max'],
                        ], $item['services']),
                    ];
                }

                $petQueueNumbers = app(DailyPetQueue::class)->assignBookingPets($booking, $queueDate, $sequence['first_pet_number']);
                foreach ($petsResponse as &$petResponse) {
                    $petResponse['pet_queue_number'] = $petQueueNumbers[$petResponse['booking_pet_id']];
                }
                unset($petResponse);

                $response = response()->json([
                    'success' => true,
                    'booking_reference' => $bookingReference,
                    'queue_number' => $queueNumber,
                    'booking_id' => $booking->booking_id,
                    'consent' => $signedConsent->only(['grooming_consent', 'sedation_consent', 'signer_name', 'signed_at', 'consent_version']),
                    'booking_date' => $booking->booking_date,
                    'status' => $booking->status,
                    'owner' => [
                        'name' => trim("{$walkin->fname} {$walkin->lname}"),
                        'email' => $walkin->email,
                        'phone' => $walkin->phone,
                    ],
                    'pets' => $petsResponse,
                    'total_amount' => $totalAmount,
                    'returning_customer' => $user !== null || $unregisteredCustomer !== null,
                ], 201);
                if ($submissionKey) {
                    DB::table('grooming_walkin_submissions')->insert([
                        'submission_key' => $submissionKey,
                        'actor_user_id' => $request->user()->user_id,
                        'payload_hash' => $payloadHash,
                        'booking_id' => $booking->booking_id,
                        'response' => $response->getContent(),
                    ]);
                }

                return $response;
            });
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);
            throw ValidationException::withMessages(['walk_in' => 'The visit could not be registered. Ask an administrator to check the queue.']);
        }
    }

    private function resolveOwner(array $data): array
    {
        $recordType = $data['owner_record_type'] ?? 'new';
        $user = null;
        $unregisteredCustomer = null;

        if ($recordType === 'new') {
            $this->validateNewOwner($data);
        }

        if ($recordType === 'registered') {
            $user = User::query()
                ->where('user_id', $data['customer_user_id'] ?? null)
                ->registeredCustomer()
                ->where('is_active', true)
                ->where('is_archived', false)
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'customer_user_id' => ['The selected active customer is no longer available.'],
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
        }

        if ($user && $user->phone === null) {
            $data['phone'] = \App\Support\PhilippineMobile::normalize($data['phone']);
            validator(['phone' => $data['phone']], ['phone' => \App\Support\PhilippineMobile::rules()])->validate();
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

    private function validateNewOwner(array $data): void
    {
        $conflict = $this->customerIdentity->findContactConflict(
            $data['phone'],
            $data['email'] ?? null,
        );

        if ($conflict) {
            $field = $conflict['field'];
            $customerType = $conflict['recordType'] === 'registered'
                ? 'A registered customer'
                : 'An unregistered customer';

            throw ValidationException::withMessages([
                $field => $field === 'email' ? 'This email is already in use.' : $customerType.' already uses this phone number.',
            ]);
        }

        $similarCustomers = $this->customerIdentity->findCustomersWithSimilarName(
            $data['fname'],
            $data['lname'],
        );

        if ($similarCustomers->isNotEmpty() && ! ($data['confirm_similar_name'] ?? false)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'similar_customer_name',
                'message' => 'A customer with the same first and last name already exists. Do you still want to continue?',
                'similarCustomers' => $similarCustomers,
            ], 409));
        }
    }

    private function resolveAllPets(array $pets, ?User $user, ?UnregisteredCustomer $unregisteredCustomer): array
    {
        return array_map(function (array $petData, int|string $index) use ($user, $unregisteredCustomer) {
            $pet = $this->findOrCreatePet($user, $unregisteredCustomer, $petData);
            $size = $pet->hasClinicVerifiedSize()
                ? $pet->size
                : ($petData['size'] ?? $pet->groomingSize());
            if ($size && PetWeightSize::isValidSize($pet->species, $size)) {
                $pet->confirmClinicSize($size);
            }

            $slugs = array_column($petData['services'], 'service_slug');
            $packages = app(\App\Services\GroomingServiceSelection::class)->services()->where('kind', 'package')->pluck('slug')->all();
            $selectedPackages = array_values(array_intersect($slugs, $packages));
            if (count($selectedPackages) > 1) {
                throw ValidationException::withMessages(["pets.{$index}.services" => 'Select one grooming package per pet.']);
            }
            app(\App\Services\GroomingServiceSelection::class)->validate([...$petData, 'size' => $size], $selectedPackages[0] ?? null,
                array_values(array_diff($slugs, $selectedPackages)), $pet->species, "pets.{$index}");

            return [
                'pet' => $pet,
                'petData' => $petData,
                'size' => $size,
                'services' => $this->resolveServices($petData['services'], $size),
            ];
        }, $pets, array_keys($pets));
    }

    private function resolveServices(array $services, ?string $size): array
    {
        return array_map(function (array $item) use ($size) {
            $service = Service::where('slug', $item['service_slug'])->firstOrFail();

            return [
                'service_id' => $service->service_id,
                'name' => $service->service_name,
                'kind' => $service->kind,
                ...$this->resolvePrice($service, $size),
            ];
        }, $services);
    }

    private function resolvePrice(Service $service, ?string $size): array
    {
        $bounds = $this->servicePrices->bookingPriceBounds($service, $size);
        return [
            'price' => $bounds['max'] !== null && $bounds['min'] !== $bounds['max']
                ? 0 : (float) $this->servicePrices->servicePrice($service, $size),
            'price_min' => $bounds['min'],
            'price_max' => $bounds['max'],
        ];
    }

    private function findOrCreatePet(
        ?User $user,
        ?UnregisteredCustomer $unregisteredCustomer,
        array $petData,
    ): Pet {
        $petData['pet_name'] = Pet::normalizeName($petData['pet_name']);
        $ownerPetQuery = Pet::query()
            ->when(
                $user !== null,
                fn ($query) => $query->where('user_id', $user->user_id),
                fn ($query) => $query->where('unregistered_customer_id', $unregisteredCustomer?->id),
            )
            ->where('is_archived', false);

        if (! empty($petData['pet_id'])) {
            $existing = (clone $ownerPetQuery)
                ->where('pet_id', $petData['pet_id'])
                ->first();

            if (! $existing || (! $user && ! $unregisteredCustomer)) {
                throw ValidationException::withMessages([
                    'pets' => ['One of the selected pets does not belong to this customer.'],
                ]);
            }

            return $this->updateWalkInPetDetails($existing, $petData);
        }

        // Reuse an existing owner pet when a matching name is submitted.
        if ($user || $unregisteredCustomer) {
            $existing = (clone $ownerPetQuery)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($petData['pet_name'])])
                ->first();

            if ($existing) {
                return $this->updateWalkInPetDetails($existing, $petData);
            }
        }

        return Pet::create([
            'user_id' => $user?->user_id,
            ...($unregisteredCustomer ? ['unregistered_customer_id' => $unregisteredCustomer->id] : []),
            'pet_name' => $petData['pet_name'],
            'species' => $petData['species'],
            'breed' => $petData['breed'] ?? null,
            'fur_type' => ValidBreedCoat::validatedCoat($petData),
            'weight' => $petData['weight'] ?? null,
            'size' => $petData['size'] ?? null,
            'color' => ValidPetColor::validatedColor($petData),
            'medical_conditions' => $petData['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }

    private function updateWalkInPetDetails(Pet $pet, array $petData): Pet
    {
        $petData['color'] ??= $pet->color;
        $pet->update([
            'breed' => $petData['breed'] ?? $pet->breed,
            'fur_type' => ValidBreedCoat::validatedCoat($petData, $pet),
            'weight' => $petData['weight'] ?? $pet->weight,
            'size' => PetWeightSize::sizeFor($pet->species, $petData['weight'] ?? $pet->weight)
                ?? $petData['size'] ?? $pet->size,
            'color' => ValidPetColor::validatedColor($petData, $pet),
            'medical_conditions' => $petData['medical_conditions'] ?? $pet->medical_conditions,
        ]);

        return $pet;
    }
}
