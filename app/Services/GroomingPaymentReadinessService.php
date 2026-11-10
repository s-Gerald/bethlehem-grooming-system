<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Support\PetProfileSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroomingPaymentReadinessService
{
    public function __construct(
        private readonly GroomingServicePriceResolver $servicePrices,
    ) {}

    /** Build the normal per-pet payment total for completed grooming. */
    public function summarize(Booking $booking, bool $lockForUpdate = false, bool $historical = false): array
    {
        $petQuery = BookingPet::query()
            ->where('booking_id', $booking->booking_id)
            ->when(! $historical, fn ($query) => $query->with('pet'))
            ->orderBy('booking_pet_id');
        $serviceQuery = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->with('service')
            ->orderBy('booking_service_id');

        if ($lockForUpdate) {
            $petQuery->lockForUpdate();
            $serviceQuery->lockForUpdate();
        }

        $bookingPets = ! $lockForUpdate && $booking->relationLoaded('bookingPets')
            ? $booking->bookingPets->sortBy('booking_pet_id')->values() : $petQuery->get();
        $serviceRows = ! $lockForUpdate && $booking->relationLoaded('bookingServices')
            ? $booking->bookingServices->sortBy('booking_service_id')->values() : $serviceQuery->get();
        if (! $historical) (new EloquentCollection($bookingPets->all()))->loadMissing('pet');
        (new EloquentCollection($serviceRows->all()))->loadMissing('service');
        $serviceNames = $serviceRows->mapWithKeys(fn ($line) => [$line->service_id => $line->service?->service_name])->filter()->all();
        $addonNames = $this->lookupLabels(
            'addons',
            'addon_id',
            'addon_name',
            $serviceRows->pluck('addon_id')->filter()->unique()->all(),
        );
        $servicesByPet = $serviceRows->groupBy('booking_pet_id');
        $bookingTotalCents = 0;
        $firstBlockedReason = null;

        $pets = $bookingPets->map(function (BookingPet $bookingPet) use (
            $servicesByPet,
            $serviceNames,
            $addonNames,
            $historical,
            &$bookingTotalCents,
            &$firstBlockedReason,
        ) {
            $pet = $historical ? PetProfileSnapshot::pet($bookingPet->pet_profile_snapshot, $bookingPet->pet_id) : $bookingPet->pet;
            $petLabel = $pet?->pet_name ?? "Pet #{$bookingPet->booking_pet_id}";
            $lines = collect($servicesByPet->get($bookingPet->booking_pet_id, collect()))
                ->map(function (BookingService $line) use (
                    $serviceNames,
                    $addonNames,
                    $bookingPet,
                    $historical,
                ) {
                    $isAddon = $line->addon_id !== null;
                    $resolvedPrice = $historical ? [
                        'amount' => $this->centsToMoney($this->moneyToCents($line->price_at_booking)),
                        'source' => $line->price_at_booking !== null ? 'booking_snapshot' : 'unpriced',
                    ] : $this->servicePrices->bookingServicePrice(
                        $line,
                        $bookingPet->confirmed_size ?? $bookingPet->registered_size ?? $bookingPet->pet?->groomingSize(),
                    );

                    return [
                        'booking_service_id' => (int) $line->booking_service_id,
                        'line_type' => $isAddon ? 'add_on' : 'service',
                        'service_kind' => $isAddon
                            ? 'ala_carte'
                            : (($historical ? ($line->service_kind_snapshot ?? $line->service?->kind) : $line->service?->kind)
                                === 'package' ? 'package' : 'ala_carte'),
                        'service_id' => $line->service_id !== null ? (int) $line->service_id : null,
                        'addon_id' => $line->addon_id !== null ? (int) $line->addon_id : null,
                        'label' => $isAddon
                            ? ($addonNames[(int) $line->addon_id] ?? "Add-on #{$line->addon_id}")
                            : ($line->service_name_snapshot ?? $serviceNames[(int) $line->service_id] ?? "Service #{$line->service_id}"),
                        'price_at_booking' => $resolvedPrice['amount'],
                        'price_source' => $resolvedPrice['source'],
                    ];
                })
                ->values();
            $subtotalCents = $lines->sum(
                fn (array $line) => $this->moneyToCents($line['price_at_booking']),
            );
            $state = (string) ($bookingPet->grooming_state
                ?: BookingPet::GROOMING_STATE_NOT_STARTED);
            $cancelled = $state === BookingPet::GROOMING_STATE_CANCELLED;
            $paymentReady = $cancelled || ($state === BookingPet::GROOMING_STATE_FINISHED
                && $bookingPet->grooming_end_time !== null);
            $blockedReason = $paymentReady
                ? null
                : $petLabel.' is '.$this->groomingStateLabel($state).'.';

            if ($paymentReady) {
                $bookingTotalCents += $cancelled ? 0 : $subtotalCents;
            } elseif ($firstBlockedReason === null) {
                $firstBlockedReason = $blockedReason;
            }

            return [
                'booking_pet_id' => (int) $bookingPet->booking_pet_id,
                'pet_id' => $bookingPet->pet_id !== null ? (int) $bookingPet->pet_id : null,
                'pet_name' => $petLabel,
                'pet_species' => $pet?->species,
                'grooming_state' => $state,
                'grooming_state_label' => $this->groomingStateLabel($state),
                'grooming_finish_time' => $bookingPet->grooming_end_time?->toIso8601String(),
                'payment_kind' => $cancelled ? 'cancelled' : ($paymentReady ? 'finished' : 'blocked'),
                'payment_ready' => $paymentReady,
                'payment_blocked_reason' => $blockedReason,
                'service_breakdown' => $lines->all(),
                'original_pet_subtotal' => $this->centsToMoney($subtotalCents),
                'final_pet_charge' => $paymentReady
                    ? $this->centsToMoney($cancelled ? 0 : $subtotalCents)
                    : null,
            ];
        })->values();

        if ($bookingPets->isEmpty()) {
            $firstBlockedReason = 'This visit has no pets to prepare for payment.';
        } elseif ($firstBlockedReason === null && $bookingTotalCents <= 0) {
            $firstBlockedReason = 'This visit has no chargeable grooming services.';
        }

        return [
            'payment_ready' => $bookingPets->isNotEmpty() && $firstBlockedReason === null,
            'payment_blocked_reason' => $firstBlockedReason,
            'final_booking_total' => $firstBlockedReason === null
                ? $this->centsToMoney($bookingTotalCents)
                : null,
            'pets' => $pets->all(),
        ];
    }

    public function moneyToCents(string|int|float|null $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) ($whole === '' ? 0 : $whole) * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public function centsToMoney(int $cents): string
    {
        $prefix = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $prefix, intdiv($absolute, 100), $absolute % 100);
    }

    private function groomingStateLabel(string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_IN_PROGRESS => 'In progress',
            BookingPet::GROOMING_STATE_FINISHED => 'Finished',
            BookingPet::GROOMING_STATE_CANCELLED => 'Cancelled',
            default => 'Not started',
        };
    }

    private function lookupLabels(
        string $table,
        string $key,
        string $label,
        array $ids,
    ): array {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($key, $ids)
            ->pluck($label, $key)
            ->mapWithKeys(fn ($value, $id) => [(int) $id => $value])
            ->all();
    }
}
