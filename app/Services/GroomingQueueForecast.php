<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicSetting;
use App\Models\PetGroomer;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GroomingQueueForecast
{
    private ?Collection $catalog = null;
    private ?array $physicalSnapshot = null;
    private ?ClinicSetting $settings = null;

    public function catalog(): Collection
    {
        return $this->catalog ??= Service::all();
    }

    private function settings(): ClinicSetting
    {
        // Unlike current(), this read never creates missing operational settings.
        return $this->settings ??= ClinicSetting::query()->find(ClinicSetting::SINGLETON_ID)
            ?? new ClinicSetting(ClinicSetting::availabilityDefaults());
    }

    private function liveJobs(): array
    {
        return app(OperationalCapacity::class)->groomingPets()
            ->with(['pet', 'booking.bookingServices.service'])
            ->where(fn ($query) => $query->whereNotNull('pet_queue_number')->orWhereNotNull('grooming_start_time'))
            ->orderBy('pet_queue_date')->orderBy('pet_queue_number')
            ->orderBy('booking_pet_id')->get()->map(fn ($pet) => [
                'key' => (string) $pet->booking_pet_id,
                'queue_number' => $pet->pet_queue_date?->format('Ymd').str_pad((string) $pet->pet_queue_number, 12, '0', STR_PAD_LEFT),
                'groomer_id' => $pet->groomer_id,
                'started_at' => $pet->grooming_start_time,
                'duration' => app(GroomingDuration::class)->forPet($pet, $this->catalog()),
            ])->all();
    }

    public function snapshot(array $additional = []): array
    {
        if (! $additional && $this->physicalSnapshot !== null) return $this->physicalSnapshot;
        $now = Carbon::now('Asia/Manila');
        $hours = $this->settings()->serviceAvailability('grooming');
        $this->cachedJobs = $this->liveJobs();
        $this->cachedGroomers = PetGroomer::where('on_duty', true)->pluck('id')->all();
        $this->cachedOpens = $now->toDateString().' '.$hours['open_time'];
        $result = $this->project(array_merge($this->cachedJobs, $additional), $this->cachedGroomers, $now,
            Carbon::parse($this->cachedOpens, 'Asia/Manila'),
            Carbon::parse($now->toDateString().' '.$hours['close_time'], 'Asia/Manila'));
        if (! $additional) $this->physicalSnapshot = $result;
        return $result;
    }

    public function preview(array $pets, bool $walkIn, bool $durationsOnly = false): array
    {
        $today = Carbon::now('Asia/Manila')->toDateString();
        $day = $durationsOnly || $walkIn ? $today : app(RegistrationServiceDay::class)->availability('grooming', $this->settings())['service_day'];
        $jobs = [];
        // Match DailyPetQueue: dogs first, cats second, then submission order.
        $ordered = collect($pets)->sortBy(fn ($pet) => strtolower($pet['species'] ?? '') === 'dog' ? 0 : 1);
        $position = 0;
        foreach ($ordered as $index => $pet) {
            $jobs[] = ['key' => 'selection-'.$index, 'queue_number' => '99999999'.str_pad((string) $position++, 12, '0', STR_PAD_LEFT),
                'groomer_id' => $pet['groomer_id'] ?? null, 'started_at' => null,
                'duration' => app(GroomingDuration::class)->estimate($pet, $this->catalog()->where('is_active', '!=', false))];
        }
        $forecast = ! $durationsOnly && $day === $today ? $this->snapshot($jobs) : null;
        $selected = [];
        foreach ($jobs as $job) {
            $index = (int) substr($job['key'], strlen('selection-'));
            $selected[$index] = ($forecast['pets'][$job['key']] ?? [
                'duration' => $job['duration'], 'start_at' => null, 'completion_at' => null,
                'closing_risk' => false, 'status' => 'unavailable',
            ]) + ['provisional' => true];
        }
        ksort($selected);
        return $this->visitSummary(array_values($selected)) + ['pets' => array_values($selected),
            'service_day' => $day, 'closing_at' => $forecast['closing_at'] ?? null,
            'generated_at' => Carbon::now('Asia/Manila')->toIso8601String()];
    }

    /** Reuse one physical snapshot and catalogue for every booking in a response. */
    public function forBookings(Collection $bookings): array
    {
        $live = $bookings->contains(fn ($booking) => in_array($booking->status, ['checked_in', 'in_progress', 'waiting_to_arrive'], true));
        $snapshot = $live ? $this->snapshot() : ['pets' => []];
        foreach ($bookings as $booking) {
            foreach ($booking->bookingPets as $pet) {
                $pet->setRelation('booking', $booking);
                $terminal = in_array($pet->grooming_state, [BookingPet::GROOMING_STATE_CANCELLED, BookingPet::GROOMING_STATE_FINISHED], true)
                    || $pet->grooming_end_time || ! in_array($booking->status, ['checked_in', 'in_progress', 'waiting_to_arrive'], true);
                $estimate = $terminal ? null : ($snapshot['pets'][$pet->booking_pet_id] ?? null);
                $pet->setAttribute('grooming_forecast', $terminal ? null : ($estimate ?? [
                    'duration' => app(GroomingDuration::class)->forPet($pet, $this->catalog()),
                    'start_at' => null, 'completion_at' => null, 'closing_risk' => false, 'status' => 'unavailable',
                ]) + ['provisional' => $booking->status === 'waiting_to_arrive']);
                $pet->unsetRelation('booking');
            }
            if ($booking->status === 'waiting_to_arrive' && $booking->booking_date === Carbon::now('Asia/Manila')->toDateString()) {
                $jobs = $booking->bookingPets->filter(fn ($pet) => $pet->grooming_forecast !== null)
                    ->sortBy(fn ($pet) => strtolower($pet->pet?->species ?? '') === 'dog' ? 0 : 1)->values()
                    ->map(fn ($pet, $index) => ['key' => 'incoming-'.$pet->booking_pet_id,
                        'queue_number' => '99999999'.str_pad((string) $index, 12, '0', STR_PAD_LEFT),
                        'groomer_id' => $pet->groomer_id, 'started_at' => null, 'duration' => $pet->grooming_forecast['duration']])->all();
                // Project using already-loaded physical jobs; no per-booking database queries.
                $projection = $this->project(array_merge($this->cachedJobs, $jobs), $this->cachedGroomers,
                    Carbon::parse($snapshot['generated_at']), Carbon::parse($this->cachedOpens), Carbon::parse($snapshot['closing_at']));
                foreach ($booking->bookingPets as $pet) {
                    if (isset($projection['pets']['incoming-'.$pet->booking_pet_id])) {
                        $pet->setAttribute('grooming_forecast', $projection['pets']['incoming-'.$pet->booking_pet_id] + ['provisional' => true]);
                    }
                }
            }
        }
        return $snapshot;
    }

    private array $cachedJobs = [];
    private array $cachedGroomers = [];
    private ?string $cachedOpens = null;

    private function visitSummary(array $pets): array
    {
        $known = collect($pets)->every(fn ($pet) => $pet['completion_at'] !== null);
        return ['closing_risk' => collect($pets)->contains('closing_risk', true),
            'start_at' => $known ? collect($pets)->pluck('start_at')->sort()->first() : null,
            'completion_at' => $known ? collect($pets)->pluck('completion_at')->sort()->last() : null,
            'status' => $known ? 'estimated' : 'uncertain'];
    }

    /** Read-only simulation. Exact times use conservative maximum durations. */
    public function project(array $jobs, array $groomers, Carbon $now, Carbon $opens, Carbon $closes): array
    {
        $base = $now->max($opens)->copy();
        $lanes = [];
        foreach ($groomers as $id) $lanes[$id] = ['upper' => $base->copy(), 'lower' => $base->copy()];
        usort($jobs, fn ($a, $b) => (! empty($b['started_at']) <=> ! empty($a['started_at']))
            ?: strcmp(str_pad((string) $a['queue_number'], 24, '0', STR_PAD_LEFT), str_pad((string) $b['queue_number'], 24, '0', STR_PAD_LEFT)));
        $pets = [];
        $activeAssignments = [];
        $uncertainRoster = false;
        foreach ($jobs as $job) {
            $active = ! empty($job['started_at']);
            $duration = $job['duration'];
            $groomer = $job['groomer_id'] ?? null;
            if ($active) {
                if ($groomer === null || ! isset($lanes[$groomer]) || isset($activeAssignments[$groomer])) $uncertainRoster = true;
                if ($groomer !== null) $activeAssignments[$groomer] = true;
            } elseif ($uncertainRoster) {
                // Active sessions must fit both named lanes and the existing global limit.
                foreach ($lanes as &$slot) $slot['upper'] = null;
                unset($slot);
            }
            if ($groomer === null && ! $active && $lanes) {
                // Prefer a calculable lane; a provisional choice never becomes an assignment.
                $choices = array_keys($lanes);
                usort($choices, fn ($a, $b) => ($lanes[$a]['upper']?->getTimestamp() ?? PHP_INT_MAX)
                    <=> ($lanes[$b]['upper']?->getTimestamp() ?? PHP_INT_MAX));
                $groomer = $choices[0];
            }
            $lane = $lanes[$groomer] ?? null;
            $start = $active ? Carbon::parse($job['started_at'])->setTimezone('Asia/Manila') : ($lane['upper'] ?? null);
            $end = $start && $duration ? $start->copy()->addMinutes($duration['max_minutes']) : null;
            $delayed = $active && $end && $end->lte($now);
            if ($delayed) $end = null;
            $lowerStart = $active ? $start : ($lane['lower'] ?? $base);
            $lowerEnd = $duration ? $lowerStart->copy()->addMinutes($duration['min_minutes'])->max($base)->copy() : $lowerStart->copy();
            $risk = ($start && $start->gt($closes))
                || ($end ? $end->gt($closes) : ($lane !== null && $lowerEnd->gt($closes)));
            $pets[$job['key']] = [
                'duration' => $duration,
                'status' => $delayed ? 'delayed' : ($end ? 'estimated' : ($lane && $duration ? 'uncertain' : 'unavailable')),
                'start_at' => $active || $end ? $start?->toIso8601String() : null,
                'completion_at' => $end?->toIso8601String(),
                'closing_risk' => $risk,
                'projected_groomer_id' => $groomer,
            ];
            if ($lane !== null) {
                $lanes[$groomer] = ['upper' => $end, 'lower' => $lowerEnd];
            }
        }
        $knownEnds = collect($pets)->pluck('completion_at')->filter()->sort()->values();
        $uncertain = collect($pets)->contains(fn ($pet) => $pet['completion_at'] === null);
        return ['pets' => $pets, 'closing_at' => $closes->toIso8601String(),
            'completion_at' => $uncertain ? null : $knownEnds->last(),
            'closing_risk' => collect($pets)->contains('closing_risk', true),
            'status' => $uncertain ? 'uncertain' : 'estimated', 'generated_at' => $now->toIso8601String()];
    }
}
