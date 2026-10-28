<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use App\Services\InventoryBatchBalanceService;
use App\Services\ClinicBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminVaccinationController extends Controller
{
    public const RESPONSE_RELATIONS = [
        'clinicAppointment:id,appointment_reference',
        'inventoryItem:item_id,item_name,category,unit',
        'recordedBy:user_id,first_name,last_name',
        'publishedBy:user_id,first_name,last_name',
        'voidedBy:user_id,first_name,last_name',
    ];

    public function __construct(
        private readonly InventoryBatchBalanceService $batchBalances,
    ) {
    }

    public function options(Request $request)
    {
        $actor = $request->user();
        if ($request->boolean('clinical_only')) {
            return response()->json([
                'success' => true,
                'veterinarians' => User::activeVeterinarian()->orderBy('first_name')->orderBy('last_name')
                    ->get(['user_id', 'first_name', 'last_name'])
                    ->map(fn (User $user) => ['user_id' => $user->user_id, 'name' => $this->formatUserName($user) ?? "User #{$user->user_id}"]),
                'current_user_id' => $actor->user_id,
                'current_user_name' => $this->formatUserName($actor),
                'current_user_is_veterinarian' => $actor->isVeterinarian(),
                'administration_routes' => VaccinationRecord::ADMINISTRATION_ROUTES,
            ]);
        }
        $items = InventoryItem::query()
            ->where('category', 'vaccine')
            ->orderBy('item_name')
            ->get();
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));

        $vaccines = $items->map(function (InventoryItem $item) use ($balances) {
            $balance = $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance();
            return [
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'category' => $item->category,
                'unit' => $item->unit,
                ...$this->batchBalances->treatmentAvailability($item, $balance),
            ];
        })->values();

        return response()->json(['success' => true, 'vaccines' => $vaccines]);
    }

    public function index(Request $request, int $petId)
    {
        $pet = $this->findPet($petId);
        $data = $request->validate(['clinic_appointment_id' => 'sometimes|integer|min:1']);

        $records = VaccinationRecord::query()
            ->where('pet_id', $pet->pet_id)
            ->when(isset($data['clinic_appointment_id']), fn ($query) => $query->where('clinic_appointment_id', $data['clinic_appointment_id']))
            ->with(self::RESPONSE_RELATIONS)
            ->orderByDesc('administered_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VaccinationRecord $record) => $this->formatRecord($record))
            ->values();

        return response()->json([
            'success' => true,
            'pet' => $this->formatPet($pet),
            'vaccinations' => $records,
        ]);
    }

    public function store(Request $request, int $petId)
    {
        return DB::transaction(function () use ($request, $petId) {
            $pet = $this->findPet($petId);
            Pet::whereKey($petId)->lockForUpdate()->firstOrFail();
            $data = $this->validateClinicalData($request, $pet->pet_id);
            if (! empty($data['request_key'])) {
                $existing = VaccinationRecord::where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if ((int) $existing->pet_id !== $petId || (int) $existing->clinic_appointment_id !== (int) ($data['clinic_appointment_id'] ?? 0)) {
                        throw ValidationException::withMessages(['request_key' => 'This treatment request has already been used.']);
                    }
                    return response()->json(['success' => true, 'message' => 'Treatment already saved.',
                        'vaccination' => $this->formatRecord($this->loadResponseRelations($existing))]);
                }
            }
            $data = $this->addInventorySnapshot($data);

            if (! empty($data['clinic_appointment_id'])) {
                $case = ClinicAppointment::query()->whereKey($data['clinic_appointment_id'])->lockForUpdate()->firstOrFail();
                if (! $case->clinicalContentEditableFor($request->user()) || $case->paid || $case->archived_at) {
                    throw ValidationException::withMessages([
                        'clinic_appointment_id' => 'This case is no longer ongoing. Start a new case to add a vaccination.',
                    ]);
                }
            }

            $record = VaccinationRecord::create([
                ...$data,
                'pet_id' => $pet->pet_id,
                'recorded_by_user_id' => $request->user()->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => $record->treatment_type === 'medicine' ? 'Medicine treatment draft created.' : 'Vaccination draft created.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ], 201);
        });
    }

    public function show(int $petId, int $vaccinationId)
    {
        $this->findPet($petId);
        $record = $this->findScopedRecord($petId, $vaccinationId);

        return response()->json([
            'success' => true,
            'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
        ]);
    }

    public function update(Request $request, int $petId, int $vaccinationId)
    {
        $this->findPet($petId);

        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
            $scope = $this->findScopedRecord($petId, $vaccinationId);
            if ($scope->clinic_appointment_id) {
                ClinicAppointment::whereKey($scope->clinic_appointment_id)->lockForUpdate()->firstOrFail();
            }
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            if ($record->published_at !== null || $record->voided_at !== null) {
                return $this->conflict(
                    'Published or voided vaccination records cannot be edited. Void an incorrect published record and create a corrected replacement.',
                );
            }

            $data = $this->validateClinicalData($request, $petId, $record);
            if (array_key_exists('request_key', $data) && $record->request_key !== null && $data['request_key'] !== $record->request_key) {
                throw ValidationException::withMessages(['request_key' => 'The treatment request cannot be changed. Reload the draft.']);
            }
            $caseIds = array_filter(array_unique([$record->clinic_appointment_id, $data['clinic_appointment_id'] ?? null]));
            if ($caseIds) {
                $cases = ClinicAppointment::query()->whereKey($caseIds)->orderBy('id')->lockForUpdate()->get();
                if ($cases->contains(fn ($case) => ! $case->clinicalContentEditableFor($request->user()) || $case->paid || $case->archived_at)) {
                    return $this->conflict('This case is no longer eligible for clinical changes.');
                }
            }
            $data = $this->addInventorySnapshot($data);

            $record->fill($data);
            $record->save();

            return response()->json([
                'success' => true,
                'message' => $record->treatment_type === 'medicine' ? 'Medicine treatment draft updated.' : 'Vaccination draft updated.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    public function destroy(Request $request, int $petId, int $vaccinationId)
    {
        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
            $scope = $this->findScopedRecord($petId, $vaccinationId);
            $case = ClinicAppointment::whereKey($scope->clinic_appointment_id)->lockForUpdate()->first();
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);
            if (! $case || (int) $record->clinic_appointment_id !== (int) $case->id
                || (int) $case->pet_id !== $petId || $case->paid || $case->archived_at
                || ! in_array($case->status, ['checked_in', 'in_consultation'], true)
                || ! $case->clinicalContentEditableFor($request->user())
                || $record->published_at !== null || $record->voided_at !== null) {
                return $this->conflict('Only an unfinalized treatment in an active visit can be removed.');
            }
            $record->delete();
            return response()->json(['success' => true, 'message' => 'Treatment removed.']);
        });
    }

    public function publish(Request $request, int $petId, int $vaccinationId)
    {
        $pet = $this->findPet($petId);
        $validated = $request->validate([
            ...$this->serverManagedRules(),
            'finish_case' => ['sometimes', 'boolean'],
            'consume_inventory' => ['sometimes', 'boolean'],
        ]);
        $finishCase = (bool) ($validated['finish_case'] ?? false);

        return DB::transaction(function () use ($request, $pet, $petId, $vaccinationId, $finishCase, $validated) {
            $scope = $this->findScopedRecord($petId, $vaccinationId);
            if ($scope->clinic_appointment_id) {
                ClinicAppointment::whereKey($scope->clinic_appointment_id)->lockForUpdate()->firstOrFail();
            }
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            if ($record->voided_at !== null) {
                return $this->conflict('A voided vaccination record cannot be published again.');
            }

            if ($record->published_at !== null) {
                return $this->conflict('This vaccination record has already been published.');
            }

            if (! $record->hasPublishingRequirements()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The vaccination draft is not ready to publish.',
                    'errors' => [
                        'publication' => [
                            'Complete the required fields marked *. Treatment name and administration date are required.',
                        ],
                    ],
                ], 422);
            }

            $usesInventory = $record->inventory_item_id !== null;
            if (array_key_exists('consume_inventory', $validated) && (bool) $validated['consume_inventory'] !== $usesInventory) {
                throw ValidationException::withMessages(['inventory_item_id' => 'The Inventory link has changed. Reload and review the vaccination draft before publishing.']);
            }
            if (! $usesInventory && $finishCase) {
                throw ValidationException::withMessages([
                    'finish_case' => 'Historical or external records cannot finish a current Clinic case.',
                ]);
            }

            if ($usesInventory && $record->administered_date->toDateString() !== now()->toDateString()) {
                throw ValidationException::withMessages([
                    'administered_date' => 'This draft is not from today. Record it as historical or external without an Inventory link; its administration date must be preserved.',
                ]);
            }

            $caseToFinish = null;
            $case = null;
            if ($usesInventory && $record->clinic_appointment_id !== null) {
                $case = ClinicAppointment::query()->whereKey($record->clinic_appointment_id)->lockForUpdate()->first();
                if (! $case || (int) $case->pet_id !== $petId
                    || $case->paid || $case->archived_at
                    || ! $case->clinicalContentEditableFor($request->user())) {
                    return $this->conflict('This case is already closed or is not eligible for vaccination.');
                }
                if ($finishCase) {
                    $case->assertCompletionVitals();
                    $caseToFinish = $case;
                }
            } elseif ($finishCase) {
                throw ValidationException::withMessages(['finish_case' => 'Select an ongoing Clinic case before finishing it.']);
            }

            $record->setRelation('pet', $pet);
            app(\App\Services\ClinicTreatmentFinalizer::class)->commit($record, $case, $request->user());
            $caseToFinish?->prepareForPayment();

            return response()->json([
                'success' => true,
                'message' => $record->treatment_type === 'medicine' ? 'Medicine treatment published.' : 'Vaccination record published.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    public function void(Request $request, int $petId, int $vaccinationId)
    {
        $this->findPet($petId);

        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
            $scope = $this->findScopedRecord($petId, $vaccinationId);
            if ($scope->clinic_appointment_id) {
                ClinicAppointment::whereKey($scope->clinic_appointment_id)->lockForUpdate()->firstOrFail();
            }
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            $validated = $request->validate([
                'void_reason' => ['required', 'string', 'max:500'],
                'pet_id' => ['prohibited'],
                'recorded_by_user_id' => ['prohibited'],
                'published_at' => ['prohibited'],
                'published_by_user_id' => ['prohibited'],
                'voided_at' => ['prohibited'],
                'voided_by_user_id' => ['prohibited'],
            ]);

            if ($record->published_at === null) {
                return $this->conflict('Draft vaccination records cannot use the published-record void workflow.');
            }

            if ($record->voided_at !== null) {
                return $this->conflict('This vaccination record has already been voided.');
            }

            $case = $record->clinic_appointment_id !== null
                ? ClinicAppointment::whereKey($record->clinic_appointment_id)->lockForUpdate()->firstOrFail()
                : null;
            if ($case && ($case->paid || $case->archived_at || ! $case->clinicalContentEditableFor($request->user()))) {
                return $this->conflict('This case is no longer eligible for clinical changes.');
            }

            $record->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => $request->user()->user_id,
                'void_reason' => $validated['void_reason'],
            ])->save();

            if ($case) {
                // Voiding corrects the bill; physical stock usage and its audit remain recorded.
                $case->charges()->where('vaccination_record_id', $record->id)->delete();
                app(ClinicBillingService::class)->updateTotal($case);
            }

            return response()->json([
                'success' => true,
                'message' => 'Vaccination record voided.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    private function validateClinicalData(
        Request $request,
        int $petId,
        ?VaccinationRecord $record = null,
    ): array {
        $updating = $record !== null;
        $optional = $updating ? ['sometimes', 'nullable'] : ['nullable'];
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $usesInventory = filled($request->input('inventory_item_id', $record?->inventory_item_id));
        $type = $request->input('treatment_type', $record?->treatment_type ?? 'vaccine');
        $medicine = $type === 'medicine';

        $validator = Validator::make($request->all(), [
            'treatment_type' => ['sometimes', 'required', Rule::in(['vaccine', 'medicine'])],
            'request_key' => [...$optional, 'uuid'],
            'inventory_quantity' => [...$optional, 'integer', 'min:1', 'max:99999999'],
            'expected_price' => [...$optional, 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'expected_weight_kg' => [...$optional, 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'strength_concentration' => ['prohibited'],
            'next_due_date' => [...$optional, 'date', ...($usesInventory ? ['after_or_equal:today'] : [])],
            'dose_amount' => [...$optional, function ($attribute, $value, $fail) {
                $errors = \App\Support\ClinicClinicalRules::doseErrors($value, null);
                if (isset($errors['dose_amount'])) $fail($errors['dose_amount']);
            }],
            'dose_unit' => [...$optional, 'string', 'max:30'],
            'dose_unit_other' => ['sometimes', 'nullable', 'string', 'max:30'],
            'route' => [...$optional, Rule::in(VaccinationRecord::ADMINISTRATION_ROUTES)],
            'administration_site' => [...$optional, 'string', 'max:100'],
            'clinic_appointment_id' => [
                ...$optional,
                'integer',
                Rule::exists('clinic_appointments', 'id')
                    ->where(fn ($query) => $query->where('pet_id', $petId)),
            ],
            'inventory_item_id' => [
                ...($usesInventory ? $required : $optional),
                'integer',
                Rule::exists('inventory_items', 'item_id')
                    ->where(fn ($query) => $query->where('category', $type)->where('is_active', 1)),
            ],
            'notes' => [...$optional, 'string'],
            ...$this->serverManagedRules(),
            ...($usesInventory ? $this->systemFilledRules() : [
                'vaccine_name' => [...$optional, 'string', 'max:150'],
                'administered_date' => [...$optional, 'date', 'before_or_equal:today'],
                'product_name' => [...$optional, 'string', 'max:150'],
                'manufacturer' => [...$optional, 'string', 'max:150'],
                'batch_number' => [...$optional, 'string', 'max:100'],
                'product_expiry_date' => [...$optional, 'date'],
            ]),
        ], [
            'next_due_date.after_or_equal' => 'The next vaccination date cannot be before today.',
        ]);

        $validator->after(function ($validator) use ($request, $record): void {
            $amount = $request->input('dose_amount', $record?->dose_amount);
            $unit = $request->input('dose_unit', $record?->dose_unit);
            $units = array_column(\App\Support\ClinicClinicalRules::catalog()['doseUnits'], 'value');
            // Unchanged legacy units are not reinterpreted or normalized.
            $legacy = $record && $unit === $record->dose_unit;
            if (is_string($unit) && filled($unit) && ! $legacy && ! in_array($unit, $units, true)) {
                $validator->errors()->add('dose_unit', 'Select a dose unit.');
            }
            if ($unit === 'other' && ! $legacy) {
                $custom = $request->input('dose_unit_other');
                if (! is_string($custom) || trim($custom) === '' || \App\Support\ClinicClinicalRules::normalizeDoseUnit($custom) === 'other') {
                    $validator->errors()->add('dose_unit_other', 'Specify dose unit.');
                }
                $unit = is_string($custom) ? \App\Support\ClinicClinicalRules::normalizeDoseUnit($custom) : null;
            }
            foreach (\App\Support\ClinicClinicalRules::doseErrors($amount, $unit) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
        $data = $validator->validate();
        if (($data['dose_unit'] ?? null) === 'other' && (! $record || $record->dose_unit !== 'other')) {
            $custom = trim($data['dose_unit_other']);
            $data['dose_unit'] = \App\Support\ClinicClinicalRules::normalizeDoseUnit($custom);
        }
        unset($data['dose_unit_other']);
        if (! $record) $data['treatment_type'] = $type;
        if ($medicine) {
            $data = [...$data, 'next_due_date' => null, 'product_name' => null,
                'manufacturer' => null, 'batch_number' => null, 'product_expiry_date' => null];
        }
        if (! $usesInventory) $data['inventory_quantity'] = null;
        if (! $medicine || ! $usesInventory) {
            $data = [...$data, 'expected_price' => null, 'expected_weight_kg' => null, 'strength_concentration' => null];
        }
        if (! $usesInventory) {
            $administered = $data['administered_date'] ?? $record?->administered_date?->toDateString();
            foreach (['next_due_date', 'product_expiry_date'] as $field) {
                $later = array_key_exists($field, $data) ? $data[$field] : $record?->{$field}?->toDateString();
                if ($administered && $later && CarbonImmutable::parse($later)->lt(CarbonImmutable::parse($administered))) {
                    throw ValidationException::withMessages([$field => 'This date must be on or after the administration date.']);
                }
            }
        } elseif (! $updating) {
            $data['administered_date'] = now()->toDateString();
        }

        return $data;
    }

    private function serverManagedRules(): array
    {
        return [
            'id' => ['prohibited'],
            'pet_id' => ['prohibited'],
            'recorded_by_user_id' => ['prohibited'],
            'published_at' => ['prohibited'],
            'published_by_user_id' => ['prohibited'],
            'voided_at' => ['prohibited'],
            'voided_by_user_id' => ['prohibited'],
            'void_reason' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    // Current Inventory vaccinations use server-derived facts; historical records retain their facts.
    private function systemFilledRules(): array
    {
        return [
            'administered_date' => ['prohibited'],
            'vaccine_name' => ['prohibited'],
            'product_name' => ['prohibited'],
            'manufacturer' => ['prohibited'],
            'batch_number' => ['prohibited'],
            'product_expiry_date' => ['prohibited'],
        ];
    }

    // Drafts show the batch FEFO would use now; publish() fixes the batch actually consumed.
    private function addInventorySnapshot(array $data): array
    {
        if (empty($data['inventory_item_id'])) {
            return $data;
        }

        $item = InventoryItem::query()->findOrFail($data['inventory_item_id']);
        $batch = $this->batchBalances->nextAvailableBatch(
            $this->batchBalances->forItem((int) $item->item_id),
        );

        return [
            ...$data,
            'vaccine_name' => $item->item_name,
            'strength_concentration' => $item->strength_concentration,
            'product_name' => null,
            'manufacturer' => null,
            'batch_number' => $batch['batch_number'] ?? null,
            'product_expiry_date' => $batch['expiry_date'] ?? null,
        ];
    }

    private function findPet(int $petId): Pet
    {
        $pet = Pet::query()->find($petId);

        if (! $pet) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404));
        }

        return $pet;
    }

    private function findScopedRecord(
        int $petId,
        int $vaccinationId,
        bool $lockForUpdate = false,
    ): VaccinationRecord {
        $query = VaccinationRecord::query()
            ->where('pet_id', $petId)
            ->whereKey($vaccinationId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        if (! $record) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Vaccination record not found.',
            ], 404));
        }

        return $record;
    }

    private function loadResponseRelations(VaccinationRecord $record): VaccinationRecord
    {
        return $record->fresh(self::RESPONSE_RELATIONS);
    }

    private function formatPet(Pet $pet): array
    {
        return [
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
            'species' => $pet->species,
        ];
    }

    public function formatRecord(VaccinationRecord $record): array
    {
        return [
            'id' => $record->id,
            'treatment_type' => $record->treatment_type,
            'treatment_name' => $record->vaccine_name,
            'record_source' => $record->inventory_item_id ? 'inventory' : 'historical',
            'inventory_quantity' => $record->inventory_quantity,
            'expected_price' => $record->expected_price,
            'expected_weight_kg' => $record->expected_weight_kg,
            'request_key' => $record->request_key,
            'strength_concentration' => $record->strength_concentration,
            'pet_id' => $record->pet_id,
            'clinic_appointment_id' => $record->clinic_appointment_id,
            'clinic_appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'inventory_item_id' => $record->inventory_item_id,
            'inventory_item_name' => $record->inventoryItem?->item_name,
            'inventory_item_category' => $record->inventoryItem?->category,
            'inventory_unit' => $record->inventoryItem?->unit,
            'vaccine_name' => $record->vaccine_name,
            'product_name' => $record->product_name,
            'manufacturer' => $record->manufacturer,
            'batch_number' => $record->batch_number,
            'administered_date' => $record->administered_date?->toDateString(),
            'next_due_date' => $record->next_due_date?->toDateString(),
            'product_expiry_date' => $record->product_expiry_date?->toDateString(),
            'dose_amount' => $record->dose_amount,
            'dose_unit' => $record->dose_unit,
            'route' => $record->route,
            'administration_site' => $record->administration_site,
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_by_user_name' => $this->formatUserName($record->recordedBy),
            'notes' => $record->notes,
            'state' => $this->recordState($record),
            'due_status' => $record->dueStatus(),
            'published_at' => $record->published_at?->toIso8601String(),
            'published_by_user_id' => $record->published_by_user_id,
            'published_by_user_name' => $this->formatUserName($record->publishedBy),
            'voided_at' => $record->voided_at?->toIso8601String(),
            'voided_by_user_id' => $record->voided_by_user_id,
            'voided_by_user_name' => $this->formatUserName($record->voidedBy),
            'void_reason' => $record->void_reason,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    private function formatUserName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function recordState(VaccinationRecord $record): string
    {
        if ($record->voided_at !== null) {
            return 'voided';
        }

        return $record->published_at !== null ? 'published' : 'draft';
    }

    private function conflict(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }
}
