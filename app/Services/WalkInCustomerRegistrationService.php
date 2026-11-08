<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use App\Models\Walkin;

class WalkInCustomerRegistrationService
{
    public function __construct(
        private readonly CustomerIdentityService $customerIdentity,
    ) {}

    public function registerNewOwnerAtPickup(
        Booking $booking,
        ?int $createdByUserId,
    ): User|UnregisteredCustomer|null {
        if ($booking->booking_type !== 'walk_in' || ! $booking->walkin_id) {
            return null;
        }

        $walkin = Walkin::query()
            ->whereKey($booking->walkin_id)
            ->lockForUpdate()
            ->first();

        if (! $walkin || $walkin->user_id || $walkin->unregistered_customer_id) {
            return $walkin?->user ?? $walkin?->unregisteredCustomer;
        }

        $owner = $this->customerIdentity->findCurrentOwnerByPhone($walkin->phone);

        if ($owner instanceof User) {
            $walkin->update(['user_id' => $owner->user_id]);
            $booking->update(['user_id' => $owner->user_id]);
            $this->assignUnownedBookingPets($booking, userId: $owner->user_id);

            return $owner;
        }

        if (! $owner instanceof UnregisteredCustomer) {
            $owner = UnregisteredCustomer::create([
                'first_name' => $walkin->fname,
                'last_name' => $walkin->lname,
                'phone' => $walkin->phone,
                'email' => $walkin->email,
                'created_by_user_id' => $createdByUserId,
            ]);
        }

        $walkin->update(['unregistered_customer_id' => $owner->id]);
        $this->assignUnownedBookingPets($booking, unregisteredCustomerId: $owner->id);

        return $owner;
    }

    private function assignUnownedBookingPets(
        Booking $booking,
        ?int $userId = null,
        ?int $unregisteredCustomerId = null,
    ): void {
        $petIds = BookingPet::query()
            ->where('booking_id', $booking->booking_id)
            ->whereNotNull('pet_id')
            ->pluck('pet_id');

        if ($petIds->isEmpty()) {
            return;
        }

        Pet::query()
            ->whereIn('pet_id', $petIds)
            ->whereNull('user_id')
            ->whereNull('unregistered_customer_id')
            ->update([
                'user_id' => $userId,
                'unregistered_customer_id' => $unregisteredCustomerId,
            ]);
    }
}
