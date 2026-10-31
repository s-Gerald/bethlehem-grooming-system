<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClinicAppointment;

class CancellationNotificationContent
{
    public static function forVisit(Booking|ClinicAppointment|null $visit, string $service, string $stored, ?string $petName = null): array
    {
        $grooming = $visit instanceof Booking;
        if (!$petName && $grooming && preg_match("/^(.+?)'s grooming was cancelled/i", $stored, $match)) $petName = $match[1];
        $origin = $grooming ? $visit->booking_type : $visit?->appointment_type;
        $walkIn = $visit?->walkin_id || $origin === 'walk_in';
        $online = !$walkIn && $origin === ($grooming ? 'online' : 'pre_registered');
        $kind = $walkIn ? 'Walk-in' : ($online ? 'Online Registration' : 'Visit');
        $reference = $grooming ? $visit->booking_reference : $visit?->appointment_reference;
        $reason = trim((string) ($grooming ? $visit->cancellation_reason : ''));
        if (!$reason && preg_match('/Reason:\s*(.*?)(?: Other pets remain| The visit is now|$)/is', $stored, $match)) $reason = trim($match[1]);
        $cause = null;
        if (preg_match('/(?:was )?checked in for (Clinic|Grooming)/i', $stored.' '.$reason, $match)) {
            $cause = ($petName ?: 'Your pet')." was checked in for {$match[1]}.";
            $reason = '';
        }
        $actor = preg_match('/by (?:clinic staff|staff|the clinic)/i', $stored) ? ' by staff' : '';
        if (!$actor && preg_match('/by (?:owner|customer)/i', $stored)) $actor = ' by the owner';
        if (!$actor && $grooming && $visit->relationLoaded('user') && $visit->user) {
            $owner = trim($visit->user->first_name.' '.$visit->user->last_name);
            if ($owner && str_contains($stored, $owner)) $actor = ' by the owner';
        }
        $partial = $petName && $grooming && ($visit->status !== 'cancelled' || str_contains($stored, "'s grooming was cancelled"));
        $title = $partial ? 'Grooming Cancellation' : "{$service} {$kind} Cancelled";
        $body = $cause ?: ($partial ? "{$petName}'s grooming was cancelled."
            .(str_contains($stored, 'Other pets remain') ? ' Other pets remain scheduled.' : '')
            : 'Your '.($online ? 'registration' : 'visit').($reference ? " {$reference}" : '')." was cancelled{$actor}.");
        if ($reason && strtolower($reason) !== 'cancelled by clinic staff.') $body .= " Reason: {$reason}";
        return ['title' => $title, 'body' => $body,
            'admin' => "{$service} ".strtolower($kind).($reference ? " {$reference}" : '')." cancelled".($cause ? ". ".str_replace('Your pet', 'The pet', $cause) : "{$actor}."),
            'context' => $reason && strtolower($reason) !== 'cancelled by clinic staff.' ? "Reason: {$reason}" : null];
    }
}
