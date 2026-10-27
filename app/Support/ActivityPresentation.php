<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;

/** Read-only presentation of stable audit events, including older generic transitions. */
class ActivityPresentation
{
    private const TRANSITIONS = [
        'queue_entry_cancelled' => ['queue_status_changed', 'status', ['checked_in', 'in_progress', 'in_consultation', 'for_payment'], ['cancelled']],
        'pre_registration_cancelled' => ['queue_status_changed', 'status', ['waiting_to_arrive', 'incoming', 'pending'], ['cancelled']],
        'checked_in' => ['queue_status_changed', 'status', ['waiting_to_arrive', 'incoming', 'pending'], ['checked_in']],
        'check_in_reversed' => ['queue_status_changed', 'status', ['checked_in'], ['waiting_to_arrive', 'incoming', 'pending']],
        'grooming_started' => ['grooming_corrected', 'grooming_state', ['not_started'], ['in_progress']],
        'grooming_completed' => ['grooming_corrected', 'grooming_state', ['in_progress'], ['finished']],
        'grooming_cancelled' => ['grooming_corrected', 'grooming_state', ['not_started'], ['cancelled']],
        'grooming_start_reversed' => ['grooming_corrected', 'grooming_state', ['in_progress'], ['not_started']],
    ];

    // These shared workflows previously inherited the recorder's default System area.
    private const LEGACY_SHARED = ['customer_registered', 'customer_updated', 'customer_archived', 'customer_restored',
        'pet_registered', 'pet_updated', 'pet_archived', 'pet_restored', 'inventory_created', 'inventory_updated',
        'inventory_stock_adjusted', 'inventory_archived', 'inventory_restored', 'payment_recorded', 'payment_status_updated'];

    private const GROUPS = [
        'Queue & Check-in' => ['pre_registration_submitted', 'pre_registration_updated', 'pre_registration_cancelled',
            'pre_registration_expired', 'pre_registration_no_show', 'walk_in_registered', 'checked_in', 'check_in_reversed', 'queue_entry_cancelled', 'queue_status_changed'],
        'Grooming' => ['grooming_started', 'grooming_completed', 'grooming_cancelled', 'grooming_start_reversed', 'grooming_corrected', 'grooming_service_updated'],
        'Clinic' => ['consultation_started', 'consultation_completed', 'clinical_record_updated', 'treatment_updated'],
        'Customers & Pets' => ['customer_registered', 'customer_updated', 'customer_archived', 'customer_restored',
            'pet_registered', 'pet_updated', 'pet_archived', 'pet_restored'],
        'Payments' => ['payment_recorded', 'payment_status_updated', 'clinic_charge_updated'],
        'Inventory' => ['inventory_created', 'inventory_updated', 'inventory_stock_adjusted', 'inventory_archived', 'inventory_restored'],
        'Services' => ['service_created', 'service_updated', 'service_price_updated', 'service_activated', 'service_deactivated'],
        'Staff & Security' => ['staff_created', 'staff_activated', 'staff_deactivated', 'staff_role_changed', 'staff_updated',
            'email_changed', 'password_changed', 'sign_in', 'sign_in_failed', 'recovery_completed'],
        'System' => ['settings_updated'],
    ];

    public static function labels(): array
    {
        return ActivityRecorder::LABELS + ['queue_entry_cancelled' => 'Queue entry cancelled',
            'grooming_cancelled' => 'Grooming cancelled', 'grooming_start_reversed' => 'Grooming start reversed'];
    }

    public static function title(ActivityLog $event): string
    {
        foreach (self::TRANSITIONS as $key => [$action, $field, $before, $after]) {
            if ($event->action === $action
                && in_array($event->changes[$field]['before'] ?? null, $before, true)
                && in_array($event->changes[$field]['after'] ?? null, $after, true)) {
                return self::labels()[$key];
            }
        }
        return self::labels()[$event->action];
    }

    public static function typeGroups(): array
    {
        $labels = self::labels();
        $groups = [];
        foreach (self::GROUPS as $label => $keys) {
            $groups[] = ['label' => $label, 'options' => array_map(fn ($key) => ['value' => $key, 'label' => $labels[$key]], $keys)];
        }
        return $groups;
    }

    public static function filterType(Builder $query, string $key): void
    {
        $query->where(function (Builder $types) use ($key) {
            // Keep original stable filters compatible with all their historical records.
            if (isset(ActivityRecorder::LABELS[$key])) $types->where('action', $key);
            if (isset(self::TRANSITIONS[$key])) {
                [$action, $field, $before, $after] = self::TRANSITIONS[$key];
                $types->orWhere(fn ($q) => $q->where('action', $action)
                    ->whereIn('changes->'.$field.'->before', $before)->whereIn('changes->'.$field.'->after', $after));
            }
        });
    }

    public static function area(ActivityLog $event): string
    {
        if ($event->area === 'system' && in_array($event->action, self::LEGACY_SHARED, true)) return 'shared';
        return in_array($event->area, ['grooming', 'clinic', 'system', 'shared'], true) ? $event->area : 'shared';
    }

    public static function filterArea(Builder $query, string $area): void
    {
        if ($area === 'shared') {
            $query->where(fn ($q) => $q->where('area', 'shared')
                ->orWhere(fn ($legacy) => $legacy->where('area', 'system')->whereIn('action', self::LEGACY_SHARED)));
        } else {
            $query->where('area', $area);
            if ($area === 'system') $query->whereNotIn('action', self::LEGACY_SHARED);
        }
    }

    public static function accountName(ActivityLog $event): ?string
    {
        // Use the immutable snapshot, never a mutable current user lookup.
        $name = trim(explode(' · ', $event->actor_identity ?? '')[0]);
        return $name !== '' && ! preg_match('/^#\d+$/', $name) ? $name : null;
    }
}
