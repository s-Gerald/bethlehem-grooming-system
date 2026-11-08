<?php

namespace Database\Seeders;

use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Imports the fictional owners/pets from pet_clinic_owners_pets_seed.sql into
 * this app's real `users`/`unregistered_customers`/`pets` tables (that file's
 * own `owners`/`pets` schema doesn't match ours, so it can't be imported as-is).
 *
 * Owners with an email become real registered customers (`users`), matching
 * the field set EmailVerificationController::verify() uses when it promotes a
 * pending registration. Owners with no email become `unregistered_customers`,
 * matching the field set WalkInCustomerRegistrationService::registerNewOwnerAtPickup()
 * uses for walk-ins. Not wired into DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=PetClinicOwnersPetsSeeder
 */
class PetClinicOwnersPetsSeeder extends Seeder
{
    // Placeholder login for these fictional dev/test accounts only.
    private const SEED_PASSWORD = 'Seeded123!';

    public function run(): void
    {
        // [first, last, phone, email]
        // Maria Santos's phone was changed from the source file's 09171234567,
        // which collides with the seeded test Admin account.
        $owners = [
            1 => ['Maria', 'Santos', '09171234500', 'maria.santos@gmail.com'],
            2 => ['Juan', 'Dela Cruz', '09228765432', 'juandelacruz@gmail.com'],
            3 => ['Angelica', 'Reyes', '09051239876', 'angel.reyes@yahoo.com'],
            4 => ['Mark', 'Villanueva', '09173456781', null],
            5 => ['Kristine', 'Bautista', '09989871234', 'kristine.bautista@gmail.com'],
            6 => ['Ronald', 'Garcia', '09221112233', 'ronald.garcia@outlook.com'],
            7 => ['Jasmine', 'Mendoza', '09054445566', 'jasmine.mendoza@gmail.com'],
            8 => ['Paolo', 'Fernandez', '09176667788', null],
            9 => ['Cristina', 'Ramos', '09229998877', 'cristina.ramos@gmail.com'],
            10 => ['Michael', 'Torres', '09051230000', 'mike.torres@gmail.com'],
        ];

        // Owners with no email become unregistered_customers instead of users.
        $registeredOwnerIds = [1, 2, 3, 5, 6, 7, 9, 10];
        $unregisteredOwnerIds = [4, 8];

        // [owner_id, species, pet_name, breed, weight_kg, fur_type, size, medical_conditions]
        // size is lowercased/underscored to match the pets.size DB enum.
        // Tweety's source fur_type "N/A" is stored as null (more accurate for a bird).
        $pets = [
            [1, 'Dog', 'Bantay', 'Aspin (Philippine Native Dog)', 15.5, 'Short', 'medium', null],
            [2, 'Dog', 'Bruno', 'Shih Tzu', 6.2, 'Long', 'small', 'Mild skin allergy, on hypoallergenic shampoo'],
            [2, 'Cat', 'Mingming', 'Puspin (Philippine Native Cat)', 3.8, 'Short', 'small', null],
            [3, 'Cat', 'Luna', 'Persian', 4.1, 'Long', 'small', 'Prone to hairballs'],
            [4, 'Dog', 'Rocky', 'Aspin (Philippine Native Dog)', 18.0, 'Short', 'medium', null],
            [4, 'Dog', 'Max', 'Siberian Husky', 24.5, 'Medium', 'large', 'Hip dysplasia - limited strenuous exercise'],
            [4, 'Rabbit', 'Snowy', 'Holland Lop', 1.8, 'Short', 'small', null],
            [5, 'Cat', 'Milo', 'British Shorthair', 4.6, 'Short', 'medium', null],
            [6, 'Dog', 'Buddy', 'Labrador Retriever', 28.0, 'Short', 'large', 'Slight overweight, on diet plan'],
            [6, 'Dog', 'Coco', 'Poodle (Toy)', 3.5, 'Curly', 'small', null],
            [7, 'Cat', 'Momo', 'Puspin (Philippine Native Cat)', 3.2, 'Short', 'small', null],
            [7, 'Cat', 'Kitkat', 'Puspin (Philippine Native Cat)', 3.5, 'Short', 'small', null],
            [7, 'Dog', 'Chichi', 'Chihuahua', 2.4, 'Short', 'small', 'Heart murmur - regular vet monitoring'],
            [7, 'Bird', 'Tweety', 'Budgerigar (Budgie)', 0.04, null, 'small', null],
            [8, 'Dog', 'Zeus', 'Rottweiler', 42.0, 'Short', 'extra_large', null],
            [9, 'Cat', 'Nala', 'Persian', 4.3, 'Long', 'small', 'Sensitive stomach, prescription diet'],
            [9, 'Dog', 'Snowy', 'Pomeranian', 3.1, 'Long', 'small', null],
            [10, 'Dog', 'Duke', 'Aspin (Philippine Native Dog)', 20.0, 'Short', 'medium', 'Recovering from leg fracture, splint removed'],
        ];

        $userIds = [];
        foreach ($registeredOwnerIds as $id) {
            [$first, $last, $phone, $email] = $owners[$id];
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'phone' => $phone,
                    'password_hash' => Hash::make(self::SEED_PASSWORD),
                    'role' => 'customer',
                    'customer_tier' => 'new',
                    'is_active' => true,
                    'is_archived' => false,
                    'email_verified_at' => now(),
                ],
            );
            $userIds[$id] = $user->user_id;
        }

        $unregisteredIds = [];
        foreach ($unregisteredOwnerIds as $id) {
            [$first, $last, $phone, $email] = $owners[$id];
            $customer = UnregisteredCustomer::firstOrCreate(
                ['phone' => $phone],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'created_by_user_id' => null,
                ],
            );
            $unregisteredIds[$id] = $customer->id;
        }

        foreach ($pets as [$ownerId, $species, $name, $breed, $weight, $furType, $size, $medical]) {
            $isRegistered = in_array($ownerId, $registeredOwnerIds, true);

            Pet::firstOrCreate(
                [
                    'pet_name' => $name,
                    'user_id' => $isRegistered ? $userIds[$ownerId] : null,
                    'unregistered_customer_id' => $isRegistered ? null : $unregisteredIds[$ownerId],
                ],
                [
                    'species' => $species,
                    'breed' => $breed,
                    'weight' => $weight,
                    'fur_type' => $furType,
                    'size' => $size,
                    'medical_conditions' => $medical,
                    'is_archived' => false,
                ],
            );
        }
    }
}
