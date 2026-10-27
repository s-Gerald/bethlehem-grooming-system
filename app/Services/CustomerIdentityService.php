<?php

namespace App\Services;

use App\Support\AccountEmail;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerIdentityService
{
    public function findContactConflict(string $phone, ?string $email = null): ?array
    {
        $registeredMatch = User::query()
            ->registeredCustomer()
            ->where(function ($query) use ($phone, $email) {
                $query->where('phone', $phone);

                if ($email) {
                    $query->orWhereRaw(AccountEmail::lookupColumn().' = ?', [$email]);
                }
            })
            ->first();

        if ($registeredMatch) {
            return [
                'recordType' => 'registered',
                'field' => $registeredMatch->phone === $phone ? 'phone' : 'email',
            ];
        }

        $unregisteredMatch = UnregisteredCustomer::query()
            ->availableCustomer()
            ->where(function ($query) use ($phone, $email) {
                $query->where('phone', $phone);

                if ($email) {
                    $query->orWhereRaw(AccountEmail::lookupColumn().' = ?', [$email]);
                }
            })
            ->first();

        if (! $unregisteredMatch) {
            return null;
        }

        return [
            'recordType' => 'unregistered',
            'field' => $unregisteredMatch->phone === $phone ? 'phone' : 'email',
        ];
    }

    public function findCurrentOwnerByPhone(string $phone): User|UnregisteredCustomer|null
    {
        $registered = User::query()
            ->registeredCustomer()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->first();

        if ($registered) {
            return $registered;
        }

        return UnregisteredCustomer::query()
            ->availableCustomer()
            ->where('phone', $phone)
            ->where('is_archived', false)
            ->first();
    }

    public function findCustomersWithSimilarName(string $firstName, string $lastName): Collection
    {
        $normalizedFirst = mb_strtolower(trim($firstName));
        $normalizedLast = mb_strtolower(trim($lastName));

        $registered = User::query()
            ->registeredCustomer()
            ->whereRaw('LOWER(first_name) = ?', [$normalizedFirst])
            ->whereRaw('LOWER(last_name) = ?', [$normalizedLast])
            ->get()
            ->map(fn (User $user) => [
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'phone' => $user->phone,
                'status' => $user->is_archived ? 'Archived' : ($user->is_active ? 'Active' : 'Inactive'),
            ]);

        $unregistered = UnregisteredCustomer::query()
            ->availableCustomer()
            ->whereRaw('LOWER(first_name) = ?', [$normalizedFirst])
            ->whereRaw('LOWER(last_name) = ?', [$normalizedLast])
            ->get()
            ->map(fn (UnregisteredCustomer $customer) => [
                'fullName' => trim($customer->first_name.' '.$customer->last_name),
                'phone' => $customer->phone,
                'status' => $customer->is_archived ? 'Archived' : 'Unregistered',
            ]);

        return $registered->concat($unregistered)->values();
    }
}
