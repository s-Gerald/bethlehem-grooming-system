<?php

namespace App\Http\Controllers;

use App\Models\CustomerNotification;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Support\PetWeightSize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PetController extends Controller
{
    // ── LIST ──────────────────────────────────────────────
    // GET /api/pets?archived=0   active pets (default, used by booking form)
    // GET /api/pets?archived=1   archived pets (My Pets page toggle)
    public function index(Request $request)
    {
        $archived = (int) $request->query('archived', 0);

        $pets = Pet::where('user_id', $request->user()->user_id)
            ->where('is_archived', $archived)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'pets' => $pets,
        ]);
    }

    // ── SHOW ──────────────────────────────────────────────
    // GET /api/pets/{id}
    public function show(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'pet' => $pet,
        ]);
    }

    // ── ADD ───────────────────────────────────────────────
    // POST /api/pets
    public function store(Request $request)
    {
        $request->merge(['species' => $request->input('species') ?? 'Dog']);
        $data = $request->validate([
            'pet_name' => 'required|string|max:100',
            'species' => 'nullable|string|max:50',
            'breed' => 'nullable|string|max:100',
            'gender' => 'nullable|in:male,female',
            'birthdate' => 'nullable|date',
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'weight' => ['nullable', new ValidPetWeight],
            'color' => ['nullable', 'string', new ValidPetColor],
            'medical_conditions' => 'nullable|string|max:1000',
        ]);
        $data['pet_name'] = $this->normalizePetName($data['pet_name']);
        $this->ensureOwnerPetNameIsUnique(
            $request->user()->user_id,
            $data['pet_name'],
        );
        $data = PetWeightSize::withComputedSize($data);

        $pet = Pet::create([
            'user_id' => $request->user()->user_id,
            'pet_name' => $data['pet_name'],
            'species' => $data['species'] ?? 'Dog',
            'breed' => $data['breed'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'size' => $data['size'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'color' => ValidPetColor::validatedColor($data),
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pet added successfully.',
            'pet' => $pet,
        ], 201);
    }

    // ── UPDATE ────────────────────────────────────────────
    // PUT /api/pets/{id}
    public function update(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $request->merge(['species' => $request->input('species') ?? $pet->species]);
        $data = $request->validate([
            'pet_name' => 'required|string|max:100',
            'species' => 'nullable|string|max:50',
            'breed' => 'nullable|string|max:100',
            'gender' => 'nullable|in:male,female',
            'birthdate' => 'nullable|date',
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'weight' => ['nullable', new ValidPetWeight],
            'color' => ['nullable', 'string', new ValidPetColor($pet->color)],
            'medical_conditions' => 'nullable|string|max:1000',
        ]);
        $data['pet_name'] = $this->normalizePetName($data['pet_name']);
        $this->ensureOwnerPetNameIsUnique(
            $request->user()->user_id,
            $data['pet_name'],
            $pet->pet_id,
        );
        $data = PetWeightSize::withComputedSize($data);

        $attributes = [
            'pet_name' => $data['pet_name'],
            'species' => $data['species'] ?? 'Dog',
            'breed' => $data['breed'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'size' => $data['size'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'color' => ValidPetColor::validatedColor($data, $pet),
            'medical_conditions' => array_key_exists('medical_conditions', $data) ? $data['medical_conditions'] : $pet->medical_conditions,
        ];

        $changedVerifiedFields = array_intersect(
            $this->changedClinicVerifiableFields($pet, $attributes),
            $this->clinicVerifiedFields($pet),
        );

        $pet->fill($attributes);

        if ($changedVerifiedFields !== []) {
            $remainingVerifiedFields = array_values(array_diff(
                $this->clinicVerifiedFields($pet),
                $changedVerifiedFields,
            ));
            $pet->clinic_verified_fields = $remainingVerifiedFields ?: null;
        }

        $pet->save();

        return response()->json([
            'success' => true,
            'message' => 'Pet updated successfully.',
            'pet' => $pet->fresh(),
        ]);
    }

    // ── ADMIN UPDATE ──────────────────────────────────────
    // PUT /api/admin/pets/{id}  (admin/staff — can update any customer's pet)
    public function adminUpdate(Request $request, $id)
    {
        $actor = $request->user();
        if (! in_array($actor?->role, ['admin', 'staff'], true)
            || ($actor->clinicPermissions()['access'] && ! $actor->clinicPermissions()['clinical'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $pet = Pet::where('pet_id', $id)->first();

        if (! $pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $request->merge(['species' => $request->input('species') ?? $pet->species]);
        $data = $request->validate([
            'pet_name' => 'required|string|max:100',
            'species' => 'nullable|string|max:50',
            'breed' => 'nullable|string|max:100',
            'gender' => 'nullable|in:male,female',
            'birthdate' => 'nullable|date',
            'is_neutered' => 'nullable|boolean',
            'neutered_date' => 'nullable|date',
            'is_deceased' => 'nullable|boolean',
            'deceased_date' => 'nullable|date',
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'weight' => ['nullable', new ValidPetWeight],
            'color' => ['nullable', 'string', new ValidPetColor($pet->color)],
            'medical_conditions' => 'nullable|string|max:1000',
        ]);
        $data['pet_name'] = $this->normalizePetName($data['pet_name']);
        if ($pet->user_id || $pet->unregistered_customer_id) {
            $this->ensureOwnerPetNameIsUnique(
                $pet->user_id,
                $data['pet_name'],
                $pet->pet_id,
                $pet->unregistered_customer_id,
            );
        }
        $data = PetWeightSize::withComputedSize($data);

        $attributes = [
            'pet_name' => $data['pet_name'],
            'species' => $data['species'] ?? $pet->species,
            'breed' => $data['breed'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'is_neutered' => $data['is_neutered'] ?? false,
            'neutered_date' => $data['neutered_date'] ?? null,
            'is_deceased' => $data['is_deceased'] ?? false,
            'deceased_date' => $data['deceased_date'] ?? null,
            'size' => $data['size'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'color' => ValidPetColor::validatedColor($data, $pet),
            'medical_conditions' => array_key_exists('medical_conditions', $data) ? $data['medical_conditions'] : $pet->medical_conditions,
        ];

        $changedVerifiedFields = $this->changedClinicVerifiableFields(
            $pet,
            $attributes,
        );

        $updatedPet = DB::transaction(function () use (
            $pet,
            $attributes,
            $changedVerifiedFields,
        ) {
            $pet->fill($attributes);
            $hasInformationChanges = $pet->isDirty();

            if ($changedVerifiedFields !== []) {
                $pet->clinic_verified_fields = array_values(array_unique([
                    ...$this->clinicVerifiedFields($pet),
                    ...$changedVerifiedFields,
                ]));
            }

            if (! $hasInformationChanges) {
                return $pet->fresh();
            }

            $pet->save();

            if ($pet->user_id) {
                CustomerNotification::create([
                    'user_id' => $pet->user_id,
                    'pet_id' => $pet->pet_id,
                    'type' => CustomerNotification::TYPE_PET_INFORMATION_UPDATED,
                    'message' => "Information for {$pet->pet_name} has been updated by Bethlehem Animal Clinic.",
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }

            return $pet->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Pet updated successfully.',
            'pet' => $updatedPet,
        ]);
    }

    // POST /api/admin/customer-pets/{ownerType}/{ownerId}
    public function adminStore(Request $request, string $ownerType, $ownerId)
    {
        if (! in_array($request->user()?->role, ['admin', 'staff'], true)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if (! in_array($ownerType, ['registered', 'unregistered'], true)) {
            return response()->json(['success' => false, 'message' => 'Customer type is invalid.'], 422);
        }

        $user = $ownerType === 'registered'
            ? User::registeredCustomer()->where('user_id', $ownerId)->first()
            : null;
        $unregisteredCustomer = $ownerType === 'unregistered'
            ? UnregisteredCustomer::availableCustomer()->where('id', $ownerId)->where('is_archived', false)->first()
            : null;

        if (! $user && ! $unregisteredCustomer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $request->merge(['species' => $request->input('species') ?? 'Dog']);
        $data = $request->validate([
            'pet_name' => 'required|string|max:100',
            'species' => 'nullable|string|max:50',
            'breed' => 'nullable|string|max:100',
            'gender' => 'nullable|in:male,female',
            'birthdate' => 'nullable|date',
            'is_neutered' => 'nullable|boolean',
            'neutered_date' => 'nullable|date',
            'is_deceased' => 'nullable|boolean',
            'deceased_date' => 'nullable|date',
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'weight' => ['nullable', new ValidPetWeight],
            'color' => ['nullable', 'string', new ValidPetColor],
            'medical_conditions' => 'nullable|string|max:1000',
        ]);
        $data['pet_name'] = $this->normalizePetName($data['pet_name']);
        $this->ensureOwnerPetNameIsUnique(
            $user?->user_id,
            $data['pet_name'],
            null,
            $unregisteredCustomer?->id,
        );
        $data = PetWeightSize::withComputedSize($data);

        $pet = Pet::create([
            'user_id' => $user?->user_id,
            'unregistered_customer_id' => $unregisteredCustomer?->id,
            'pet_name' => $data['pet_name'],
            'species' => $data['species'] ?? 'Dog',
            'breed' => $data['breed'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'is_neutered' => $data['is_neutered'] ?? false,
            'neutered_date' => $data['neutered_date'] ?? null,
            'is_deceased' => $data['is_deceased'] ?? false,
            'deceased_date' => $data['deceased_date'] ?? null,
            'size' => $data['size'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'color' => ValidPetColor::validatedColor($data),
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pet added successfully.',
            'pet' => $pet,
        ], 201);
    }

    private function normalizePetName(string $name): string
    {
        return Pet::normalizeName($name);
    }

    private function ensureOwnerPetNameIsUnique(
        ?int $ownerId,
        string $petName,
        ?int $ignoredPetId = null,
        ?int $unregisteredCustomerId = null,
    ): void {
        $normalizedName = mb_strtolower($this->normalizePetName($petName));
        $petNames = Pet::query()
            ->when(
                $ownerId !== null,
                fn ($query) => $query->where('user_id', $ownerId),
                fn ($query) => $query->where('unregistered_customer_id', $unregisteredCustomerId),
            )
            ->when(
                $ignoredPetId !== null,
                fn ($query) => $query->where('pet_id', '!=', $ignoredPetId),
            )
            ->pluck('pet_name');

        $duplicateExists = $petNames->contains(
            fn ($existingName) => mb_strtolower(
                $this->normalizePetName((string) $existingName),
            ) === $normalizedName,
        );

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'pet_name' => ['You already have a pet with this name.'],
            ]);
        }
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // POST /api/pets/{id}/archive
    public function archive(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $pet->update(['is_archived' => 1]);

        return response()->json(['success' => true, 'message' => 'Pet archived.']);
    }

    // ── UNARCHIVE ─────────────────────────────────────────
    // POST /api/pets/{id}/unarchive
    public function unarchive(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $pet->update(['is_archived' => 0]);

        return response()->json(['success' => true, 'message' => 'Pet restored.']);
    }

    private function clinicVerifiedFields(Pet $pet): array
    {
        return array_values(array_intersect(
            Pet::CLINIC_VERIFIABLE_FIELDS,
            is_array($pet->clinic_verified_fields)
                ? $pet->clinic_verified_fields
                : [],
        ));
    }

    private function changedClinicVerifiableFields(
        Pet $pet,
        array $attributes,
    ): array {
        return array_values(array_filter(
            Pet::CLINIC_VERIFIABLE_FIELDS,
            function (string $field) use ($pet, $attributes) {
                if (! array_key_exists($field, $attributes)) {
                    return false;
                }

                return ! $this->clinicFieldValuesMatch(
                    $field,
                    $pet->getOriginal($field),
                    $attributes[$field],
                );
            },
        ));
    }

    private function clinicFieldValuesMatch(
        string $field,
        mixed $original,
        mixed $updated,
    ): bool {
        if ($field === 'weight') {
            if (($original === null || $original === '')
                && ($updated === null || $updated === '')) {
                return true;
            }

            return is_numeric($original)
                && is_numeric($updated)
                && abs((float) $original - (float) $updated) < 0.001;
        }

        $normalize = static fn (mixed $value): ?string => $value === null
            || trim((string) $value) === ''
                ? null
                : trim((string) $value);

        return $normalize($original) === $normalize($updated);
    }
}
