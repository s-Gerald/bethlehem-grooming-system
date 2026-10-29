<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnregisteredCustomerRequest;
use App\Models\Booking;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Services\CustomerIdentityService;
use App\Support\HumanName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function updateOwnName(Request $request)
    {
        $unexpected = array_diff(array_keys($request->all()), ['first_name', 'last_name', 'current_password']);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
        }
        $data = $request->validate([
            'first_name' => HumanName::rules(),
            'last_name' => HumanName::rules(),
            'current_password' => ['required', 'string'],
        ], ['current_password.required' => 'Enter your current password.']);

        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            abort_unless($user->role === 'customer' && $user->is_active && ! $user->is_archived
                && ! $user->account_deleted_at && $user->email_verified_at, 403);
            if (! Hash::check($data['current_password'], $user->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'Incorrect password.']);
            }
            foreach (['first_name', 'last_name'] as $field) {
                if ($data[$field] !== $user->$field) $user->$field = User::normalizeName($data[$field]);
            }
            if ($user->isDirty()) $user->save();

            return $user;
        }, 3);

        $request->setUserResolver(fn () => $user);
        $response = app(AuthController::class)->me($request);
        $response->setData([...$response->getData(true), 'message' => 'Name updated.']);

        return $response;
    }

    // ── ADMIN GUARD ───────────────────────────────────────
    private function requireAdmin(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403));
        }
    }

    private function requireAdminOrStaff(Request $request)
    {
        if (! in_array($request->user()?->role, ['admin', 'staff'], true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff access required.',
            ], 403));
        }
    }

    // ── LIST CUSTOMERS ────────────────────────────────────
    // GET /api/admin/customers
    // Query params: status (active|inactive|archived|unregistered), tier (new|returning), search
    public function index(Request $request)
    {
        $this->requireAdminOrStaff($request);

        $status = $request->query('status', 'active');   // active | inactive | archived | unregistered
        $tier = $request->query('tier', '');          // new | returning | '' (all)
        $search = trim((string) $request->query('search', ''));

        if ($status === 'unregistered') {
            return $this->unregisteredIndex($search);
        }

        $query = User::registeredCustomer();

        $this->applyCustomerStatusFilter($query, $status);

        // Search
        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($q) use ($search, $nameTerms) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            });
        }

        $pastStatuses = ['cancelled', 'waiting_to_arrive', 'expired', 'no_show'];
        $users = $query->addSelect([
            'booking_count' => Booking::selectRaw('COUNT(*)')->whereColumn('bookings.user_id', 'users.user_id')
                ->whereNotIn('status', ['cancelled']),
            'past_booking_count' => Booking::selectRaw('COUNT(*)')->whereColumn('bookings.user_id', 'users.user_id')
                ->whereNotIn('status', $pastStatuses),
        ])->orderBy('first_name')->get();

        // Attach booking counts and compute tier
        $customers = $users->map(function (User $user) use ($tier) {
            $bookingCount = (int) $user->booking_count;
            $pastBookingCount = (int) $user->past_booking_count;

            $computedTier = $pastBookingCount > 0 ? 'returning' : 'new';

            // Apply tier filter after computing
            if ($tier && $computedTier !== $tier) {
                return null;
            }

            return [
                'id' => $user->user_id,
                'recordType' => 'registered',
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'isArchived' => (bool) $user->is_archived,
                'tier' => $computedTier,
                'bookingCount' => $bookingCount,
                'archivedAt' => $user->archived_at,
                'joinedAt' => $user->created_at ?? null,
            ];
        })->filter()->values();

        if ($status === 'archived' && Schema::hasTable('unregistered_customers')) {
            $customers = $customers
                ->concat($this->queryUnregisteredCustomers($search, true))
                ->sortBy('fullName', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        }

        $petMatches = collect();

        if ($search !== '') {
            $petMatches = Pet::query()
                ->with('user:user_id,first_name,last_name,phone,email')
                ->where('pet_name', 'like', "%{$search}%")
                ->whereHas('user', function ($ownerQuery) use ($status, $tier, $pastStatuses) {
                    $ownerQuery->registeredCustomer();
                    $this->applyCustomerStatusFilter($ownerQuery, $status);
                    if (in_array($tier, ['new', 'returning'], true)) {
                        $ownerQuery->whereExists(fn ($booking) => $booking->selectRaw('1')->from('bookings')
                            ->whereColumn('bookings.user_id', 'users.user_id')->whereNotIn('status', $pastStatuses),
                            'and', $tier === 'new');
                    } elseif ($tier !== '') {
                        $ownerQuery->whereRaw('1 = 0');
                    }
                })
                ->orderBy('pet_name')
                ->orderBy('pet_id')
                ->get();

            $petMatches = $petMatches->map(fn (Pet $pet) => [
                'id' => $pet->pet_id,
                'petName' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'isArchived' => (bool) $pet->is_archived,
                'ownerId' => $pet->user_id,
                'ownerName' => trim($pet->user->first_name.' '.$pet->user->last_name),
                'ownerPhone' => $pet->user->phone,
                'ownerEmail' => $pet->user->email,
            ])->values();
        }

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'total' => $customers->count(),
            'pets' => $petMatches,
            'petTotal' => $petMatches->count(),
        ]);
    }

    private function unregisteredIndex(string $search)
    {
        $customers = $this->queryUnregisteredCustomers($search, false);

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'total' => $customers->count(),
            'pets' => [],
            'petTotal' => 0,
        ]);
    }

    private function queryUnregisteredCustomers(string $search, bool $archived)
    {
        $query = UnregisteredCustomer::query()
            ->availableCustomer()
            ->with('pets')
            ->where('is_archived', $archived);

        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery
                    ->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('middle_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            });
        }

        return $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (UnregisteredCustomer $customer) => $this->formatUnregisteredCustomer($customer));
    }

    public function storeUnregistered(
        StoreUnregisteredCustomerRequest $request,
        CustomerIdentityService $customerIdentity,
    ) {
        $this->requireAdminOrStaff($request);
        $data = $request->validated();

        $this->ensureCustomerContactIsAvailable($customerIdentity, $data);

        $similarCustomers = $customerIdentity->findCustomersWithSimilarName(
            $data['first_name'],
            $data['last_name'],
        );

        if ($similarCustomers->isNotEmpty() && ! ($data['confirm_similar_name'] ?? false)) {
            return response()->json([
                'success' => false,
                'code' => 'similar_customer_name',
                'message' => 'A customer with the same first and last name already exists. Do you still want to continue?',
                'similarCustomers' => $similarCustomers,
            ], 409);
        }

        unset($data['confirm_similar_name']);

        $customer = UnregisteredCustomer::create([
            ...$data,
            'created_by_user_id' => $request->user()->user_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer record added.',
            'customer' => $this->formatUnregisteredCustomer($customer),
        ], 201);
    }

    public function showUnregistered(Request $request, $id)
    {
        $this->requireAdminOrStaff($request);

        $customer = UnregisteredCustomer::query()->availableCustomer()->with('pets')->find($id);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer record not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'customer' => $this->formatUnregisteredCustomer($customer),
        ]);
    }

    private function formatUnregisteredCustomer(UnregisteredCustomer $customer): array
    {
        $fullName = $this->unregisteredCustomerFullName($customer);

        $pets = $customer->relationLoaded('pets')
            ? $customer->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values()
            : collect();

        return [
            'id' => $customer->id,
            'recordType' => 'unregistered',
            'status' => $customer->is_archived ? 'archived' : 'unregistered',
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'middleName' => $customer->middle_name,
            'fullName' => $fullName,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'isActive' => false,
            'isArchived' => (bool) $customer->is_archived,
            'tier' => null,
            'bookingCount' => 0,
            'petCount' => $pets->count(),
            'activePetCount' => $pets->where('isArchived', false)->count(),
            'archivedPetCount' => $pets->where('isArchived', true)->count(),
            'pets' => $pets,
            'archivedAt' => $customer->archived_at,
            'joinedAt' => $customer->created_at,
        ];
    }

    private function unregisteredCustomerFullName(UnregisteredCustomer $customer): string
    {
        $middleName = trim((string) $customer->middle_name);
        $displayMiddleName = $middleName !== '' ? rtrim($middleName, '.').'.' : '';

        return trim(implode(' ', array_filter([
            $customer->first_name,
            $displayMiddleName,
            $customer->last_name,
        ])));
    }

    private function formatPet(Pet $pet): array
    {
        return [
            'id' => $pet->pet_id,
            'petName' => $pet->pet_name,
            'species' => $pet->species,
            'breed' => $pet->breed,
            'gender' => $pet->gender,
            'birthdate' => $pet->birthdate,
            'isNeutered' => (bool) $pet->is_neutered,
            'neuteredDate' => $pet->neutered_date,
            'isDeceased' => (bool) $pet->is_deceased,
            'deceasedDate' => $pet->deceased_date,
            'weight' => $pet->weight,
            'color' => $pet->color,
            'size' => $pet->size,
            'sizeVerified' => $pet->hasClinicVerifiedSize(),
            'furType' => $pet->fur_type,
            'medicalConditions' => $pet->medical_conditions,
            'isArchived' => (bool) $pet->is_archived,
            'createdAt' => $pet->created_at ?? null,
        ];
    }

    public function validateWalkInOwner(
        StoreUnregisteredCustomerRequest $request,
        CustomerIdentityService $customerIdentity,
    ) {
        $this->requireAdminOrStaff($request);
        $data = $request->validated();
        $this->ensureCustomerContactIsAvailable($customerIdentity, $data);

        $similarCustomers = $customerIdentity->findCustomersWithSimilarName(
            $data['first_name'],
            $data['last_name'],
        );

        if ($similarCustomers->isNotEmpty() && ! ($data['confirm_similar_name'] ?? false)) {
            return response()->json([
                'success' => false,
                'code' => 'similar_customer_name',
                'message' => 'A customer with the same first and last name already exists. Do you still want to continue?',
                'similarCustomers' => $similarCustomers,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Owner information is available.',
        ]);
    }

    private function ensureCustomerContactIsAvailable(
        CustomerIdentityService $customerIdentity,
        array $data,
    ): void {
        $conflict = $customerIdentity->findContactConflict(
            $data['phone'],
            $data['email'] ?? null,
        );

        if (! $conflict) {
            return;
        }

        $field = $conflict['field'];
        $customerType = $conflict['recordType'] === 'registered'
            ? 'A portal customer'
            : 'A manual customer';

        throw ValidationException::withMessages([
            $field => $field === 'email' ? 'This email is already in use.' : $customerType.' already uses this phone number.',
        ]);
    }

    public function archiveUnregistered(Request $request, $id)
    {
        return $this->changeManualArchiveState($request, $id, true);
    }

    public function restoreUnregistered(Request $request, $id)
    {
        return $this->changeManualArchiveState($request, $id, false);
    }

    private function changeManualArchiveState(Request $request, $id, bool $archive)
    {
        $this->requireAdminOrStaff($request);

        return DB::transaction(function () use ($id, $archive) {
            $customer = UnregisteredCustomer::query()->availableCustomer()->lockForUpdate()->find($id);
            if (! $customer) {
                return response()->json(['success' => false, 'message' => 'Customer record not found.'], 404);
            }
            if ($customer->is_archived === $archive) {
                return response()->json(['success' => false, 'message' => $archive ? 'Customer is already archived.' : 'Customer is not archived.'], 422);
            }
            $customer->update(['is_archived' => $archive, 'archived_at' => $archive ? now() : null]);

            return response()->json(['success' => true, 'message' => $archive ? 'Customer archived.' : 'Customer restored.']);
        });
    }

    public function walkInSearch(Request $request)
    {
        $this->requireAdminOrStaff($request);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');

        // Empty query → return all active customers with their pets (for the clinic records list)
        if ($search === '') {
            $registered = User::query()
                ->with(['pets' => fn ($q) => $q->where('is_archived', false)->orderBy('pet_name')])
                ->registeredCustomer()
                ->where('is_active', true)
                ->where('is_archived', false)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get()
                ->map(fn (User $user) => [
                    'id'         => $user->user_id,
                    'recordType' => 'registered',
                    'firstName'  => $user->first_name,
                    'lastName'   => $user->last_name,
                    'middleName' => null,
                    'fullName'   => trim($user->first_name.' '.$user->last_name),
                    'email'      => $user->email,
                    'phone'      => $user->phone,
                    'status'     => 'active',
                    'pets'       => $user->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
                ]);

            $unregistered = UnregisteredCustomer::query()
                ->availableCustomer()
                ->with(['pets' => fn ($q) => $q->where('is_archived', false)->orderBy('pet_name')])
                ->where('is_archived', false)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get()
                ->map(fn (UnregisteredCustomer $customer) => [
                    'id'         => $customer->id,
                    'recordType' => 'unregistered',
                    'firstName'  => $customer->first_name,
                    'lastName'   => $customer->last_name,
                    'middleName' => $customer->middle_name,
                    'fullName'   => trim($customer->first_name.' '.$customer->last_name),
                    'email'      => $customer->email,
                    'phone'      => $customer->phone,
                    'status'     => 'unregistered',
                    'pets'       => $customer->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
                ]);

            return response()->json([
                'success'   => true,
                'customers' => $registered->concat($unregistered)
                    ->sortBy('fullName', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values(),
                'pets'      => [],
            ]);
        }

        if (mb_strlen($search) < 2) {
            return response()->json([
                'success' => true,
                'customers' => [],
                'pets' => [],
            ]);
        }

        $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $applySearch = function ($query) use ($search, $nameTerms) {
            $query->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            });
        };

        $registeredQuery = User::query()
            ->with(['pets' => fn ($query) => $query->where('is_archived', false)])
            ->registeredCustomer()
            ->where('is_active', true)
            ->where('is_archived', false);
        $applySearch($registeredQuery);
        $registered = $registeredQuery->limit(10)->get()->map(fn (User $user) => [
            'id' => $user->user_id,
            'recordType' => 'registered',
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'middleName' => null,
            'fullName' => trim($user->first_name.' '.$user->last_name),
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => 'active',
            'pets' => $user->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
        ]);

        $unregisteredQuery = UnregisteredCustomer::query()
            ->availableCustomer()
            ->with(['pets' => fn ($query) => $query->where('is_archived', false)])
            ->where('is_archived', false);
        $applySearch($unregisteredQuery);
        $unregistered = $unregisteredQuery->limit(10)->get()->map(fn (UnregisteredCustomer $customer) => [
            'id' => $customer->id,
            'recordType' => 'unregistered',
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'middleName' => $customer->middle_name,
            'fullName' => trim($customer->first_name.' '.$customer->last_name),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => 'unregistered',
            'pets' => $customer->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
        ]);

        $pets = Pet::query()
            ->with([
                'user:user_id,first_name,last_name',
                'unregisteredCustomer:id,first_name,last_name',
            ])
            ->where('is_archived', false)
            ->where('pet_name', 'like', "%{$search}%")
            ->where(function ($ownerQuery) {
                $ownerQuery
                    ->whereHas('user', function ($userQuery) {
                        $userQuery
                            ->registeredCustomer()
                            ->where('is_active', true)
                            ->where('is_archived', false);
                    })
                    ->orWhereHas('unregisteredCustomer', function ($customerQuery) {
                        $customerQuery->availableCustomer()->where('is_archived', false);
                    });
            })
            ->orderBy('pet_name')
            ->orderBy('pet_id')
            ->limit(12)
            ->get()
            ->map(function (Pet $pet) {
                $unregisteredOwner = $pet->unregisteredCustomer;
                $owner = $unregisteredOwner ?? $pet->user;

                return [
                    ...$this->formatPet($pet),
                    'ownerId' => $unregisteredOwner?->id ?? $pet->user_id,
                    'ownerName' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                    'ownerRecordType' => $unregisteredOwner ? 'unregistered' : 'registered',
                    'ownerStatus' => $unregisteredOwner ? 'Unregistered' : 'Active',
                ];
            });

        return response()->json([
            'success' => true,
            'customers' => $registered->concat($unregistered)
                ->sortBy('fullName', SORT_NATURAL | SORT_FLAG_CASE)
                ->take(12)
                ->values(),
            'pets' => $pets,
        ]);
    }

    public function dashboardSearch(Request $request)
    {
        $this->requireAdminOrStaff($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        if ($search === '') {
            return response()->json([
                'success' => true,
                'customers' => [],
                'pets' => [],
            ]);
        }

        $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $registeredCustomers = User::query()
            ->registeredCustomer()
            ->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery->where('phone', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(8)
            ->get(['user_id', 'first_name', 'last_name', 'phone', 'is_active', 'is_archived'])
            ->map(fn (User $user) => [
                'id' => $user->user_id,
                'recordType' => 'registered',
                'name' => trim($user->first_name.' '.$user->last_name),
                'phone' => $user->phone,
                'status' => $user->is_archived ? 'Archived' : ($user->is_active ? 'Active' : 'Inactive'),
            ]);

        $unregisteredCustomers = UnregisteredCustomer::query()
            ->availableCustomer()
            ->where('is_archived', false)
            ->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery->where('phone', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('middle_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'phone'])
            ->map(fn (UnregisteredCustomer $customer) => [
                'id' => $customer->id,
                'recordType' => 'unregistered',
                'name' => trim($customer->first_name.' '.$customer->last_name),
                'phone' => $customer->phone,
                'status' => 'Unregistered',
            ]);

        $customers = $registeredCustomers
            ->concat($unregisteredCustomers)
            ->sortBy([
                ['name', 'asc'],
                ['recordType', 'asc'],
                ['phone', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->take(8)
            ->values();

        $pets = Pet::query()
            ->with([
                'user:user_id,first_name,last_name',
                'unregisteredCustomer:id,first_name,last_name',
            ])
            ->where('pet_name', 'like', "%{$search}%")
            ->where(function ($ownerQuery) {
                $ownerQuery
                    ->whereHas('user', fn ($userQuery) => $userQuery->registeredCustomer())
                    ->orWhereHas('unregisteredCustomer', fn ($customerQuery) => $customerQuery
                        ->availableCustomer()
                        ->where('is_archived', false));
            })
            ->orderBy('pet_name')
            ->orderBy('pet_id')
            ->limit(8)
            ->get(['pet_id', 'user_id', 'unregistered_customer_id', 'pet_name', 'species'])
            ->map(function (Pet $pet) {
                $unregisteredOwner = $pet->unregisteredCustomer;
                $owner = $unregisteredOwner ?? $pet->user;

                return [
                    'id' => $pet->pet_id,
                    'name' => $pet->pet_name,
                    'species' => $pet->species,
                    'ownerId' => $unregisteredOwner?->id ?? $pet->user_id,
                    'ownerName' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                    'ownerRecordType' => $unregisteredOwner ? 'unregistered' : 'registered',
                ];
            });

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'pets' => $pets,
        ]);
    }

    private function applyCustomerStatusFilter($query, string $status): void
    {
        if ($status === 'archived') {
            $query->where('is_archived', 1);

            return;
        }

        if ($status === 'inactive') {
            $query->where('is_archived', 0)->where('is_active', 0);

            return;
        }

        $query->where('is_archived', 0)->where('is_active', 1);
    }

    // ── CUSTOMER DETAILS ──────────────────────────────────
    // GET /api/admin/customers/{id}
    public function show(Request $request, $id)
    {
        $this->requireAdminOrStaff($request);

        $user = User::registeredCustomer()->where('user_id', $id)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $bookingCount = Booking::where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $pastBookingCount = Booking::where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive', 'expired', 'no_show'])
            ->count();

        $pets = Pet::where('user_id', $user->user_id)
            ->orderBy('is_archived')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Pet $pet) => [
                'id' => $pet->pet_id,
                'petName' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'gender' => $pet->gender,
                'birthdate' => $pet->birthdate,
                'isNeutered' => (bool) $pet->is_neutered,
                'neuteredDate' => $pet->neutered_date,
                'isDeceased' => (bool) $pet->is_deceased,
                'deceasedDate' => $pet->deceased_date,
                'weight' => $pet->weight,
                'color' => $pet->color,
                'size' => $pet->size,
                'sizeVerified' => $pet->hasClinicVerifiedSize(),
                'furType' => $pet->fur_type,
                'medicalConditions' => $pet->medical_conditions,
                'isArchived' => (bool) $pet->is_archived,
                'createdAt' => $pet->created_at ?? null,
            ]);

        return response()->json([
            'success' => true,
            'customer' => [
                'id' => $user->user_id,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'isArchived' => (bool) $user->is_archived,
                'tier' => $pastBookingCount > 0 ? 'returning' : 'new',
                'customerTier' => $user->customer_tier,
                'bookingCount' => $bookingCount,
                'petCount' => $pets->count(),
                'activePetCount' => $pets->where('isArchived', false)->count(),
                'archivedPetCount' => $pets->where('isArchived', true)->count(),
                'archivedAt' => $user->archived_at,
                'joinedAt' => $user->created_at ?? null,
                'pets' => $pets->values(),
            ],
        ]);
    }

    // ── DEACTIVATE ────────────────────────────────────────
    // POST /api/admin/customers/{id}/deactivate
    public function deactivate(Request $request, $id)
    {
        return $this->changePortalAccountState($request, $id, 'deactivate');
    }

    public function reactivate(Request $request, $id)
    {
        return $this->changePortalAccountState($request, $id, 'reactivate');
    }

    public function archive(Request $request, $id)
    {
        return $this->changePortalAccountState($request, $id, 'archive');
    }

    public function unarchive(Request $request, $id)
    {
        return $this->changePortalAccountState($request, $id, 'restore');
    }

    private function changePortalAccountState(Request $request, $id, string $action)
    {
        $this->requireAdmin($request);

        return DB::transaction(function () use ($id, $action) {
            $user = User::registeredCustomer()->lockForUpdate()->find($id);
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
            }

            $invalid = match ($action) {
                'archive' => $user->is_archived ? 'Customer is already archived.' : null,
                'restore' => ! $user->is_archived ? 'Customer is not archived.' : null,
                default => $user->is_archived ? 'Restore the customer before changing account access.'
                    : ($user->is_active === ($action === 'reactivate') ? 'Account access is already in that state.' : null),
            };
            if ($invalid) {
                return response()->json(['success' => false, 'message' => $invalid], 422);
            }

            $changes = match ($action) {
                'archive' => ['pre_archive_is_active' => $user->is_active, 'is_archived' => true, 'is_active' => false, 'archived_at' => now()],
                'restore' => ['is_archived' => false, 'is_active' => $user->pre_archive_is_active === true, 'archived_at' => null, 'pre_archive_is_active' => null],
                default => ['is_active' => $action === 'reactivate'],
            };
            $user->update($changes);

            if (in_array($action, ['archive', 'deactivate'], true)) {
                $user->tokens()->delete();
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->getKey())->delete();
                }
            }

            $message = match ($action) {
                'archive' => 'Customer archived.',
                'restore' => 'Customer restored.',
                'deactivate' => 'Customer account deactivated.',
                'reactivate' => 'Customer account reactivated.',
            };

            return response()->json(['success' => true, 'message' => $message]);
        });
    }
}
