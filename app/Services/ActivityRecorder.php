<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ActivityRecorder
{
    private ?bool $available = null;

    public const OPERATIONAL_ACTIONS = ['pre_registration_submitted', 'pre_registration_updated',
        'pre_registration_cancelled', 'pre_registration_expired', 'pre_registration_no_show', 'walk_in_registered', 'checked_in',
        'check_in_reversed', 'queue_status_changed', 'grooming_started', 'grooming_completed', 'grooming_corrected',
        'consultation_started', 'consultation_completed'];

    public const LABELS = [
        'suspicious_verification' => 'Suspicious verification activity detected',
        'account_otp_restricted' => 'Account-wide OTP restriction triggered',
        'security_incident_reviewed' => 'Security incident acknowledged',
        'customer_registered' => 'Customer registered', 'customer_updated' => 'Customer information updated',
        'customer_archived' => 'Customer archived', 'customer_restored' => 'Customer restored',
        'pet_registered' => 'Pet registered', 'pet_updated' => 'Pet information updated',
        'pet_archived' => 'Pet archived', 'pet_restored' => 'Pet restored',
        'pre_registration_submitted' => 'Online Registration submitted', 'pre_registration_updated' => 'Online Registration updated',
        'pre_registration_cancelled' => 'Online Registration cancelled', 'pre_registration_expired' => 'Online Registration expired',
        'pre_registration_no_show' => 'Online Registration marked No Show',
        'walk_in_registered' => 'Walk-in registered', 'checked_in' => 'Checked in', 'check_in_reversed' => 'Check-in reversed',
        'queue_status_changed' => 'Queue status changed', 'grooming_started' => 'Grooming started',
        'grooming_completed' => 'Grooming completed', 'grooming_corrected' => 'Grooming status corrected',
        'consultation_started' => 'Consultation started', 'consultation_completed' => 'Consultation completed',
        'clinical_record_updated' => 'Clinical record updated', 'treatment_updated' => 'Treatment record updated',
        'clinic_charge_updated' => 'Clinic charges updated', 'grooming_service_updated' => 'Grooming service information updated',
        'payment_recorded' => 'Payment recorded', 'payment_status_updated' => 'Payment status updated',
        'inventory_created' => 'Inventory item created', 'inventory_updated' => 'Inventory item updated',
        'inventory_stock_adjusted' => 'Inventory stock adjusted', 'inventory_archived' => 'Inventory item archived',
        'inventory_restored' => 'Inventory item restored', 'service_created' => 'Service created',
        'service_updated' => 'Service updated', 'service_price_updated' => 'Service price updated',
        'service_activated' => 'Service activated', 'service_deactivated' => 'Service deactivated',
        'staff_created' => 'Staff account created', 'staff_activated' => 'Staff account activated',
        'staff_deactivated' => 'Staff account deactivated', 'staff_role_changed' => 'Staff role changed',
        'staff_updated' => 'Staff profile information updated', 'email_changed' => 'Staff email changed',
        'password_changed' => 'Password changed', 'recovery_completed' => 'Admin-assisted account recovery completed',
        'sign_in' => 'Signed in', 'sign_in_failed' => 'Sign-in failed', 'settings_updated' => 'System settings changed',
    ];

    public const SAFE_CHANGES = ['status', 'quantity_on_hand', 'is_active', 'is_archived', 'booking_date',
        'appointment_date', 'queue_number', 'grooming_state', 'first_name', 'last_name', 'username',
        'staff_type', 'staff_subrole', 'service_name', 'name', 'base_price', 'price', 'min_price', 'max_price',
        'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max',
        'total_amount', 'payment_status', 'payment_method', 'selling_price', 'unit_cost', 'pet_name', 'species',
        'breed', 'gender', 'weight', 'size', 'color', 'unit', 'category', 'reorder_level',
        'quantity', 'unit_price', 'amount', 'price_at_booking', 'price_min_at_booking', 'price_max_at_booking'];

    public function available(): bool
    {
        $available = $this->available ??= Schema::hasTable('activity_logs');
        if (! $available && ! app()->runningUnitTests() && ! app()->runningInConsole()) {
            throw new \RuntimeException('The Activity Log migration must be applied before serving requests.');
        }
        return $available;
    }

    public function record(string $action, string $area, string $subjectType, string $subjectId, string $context,
        array $changes = [], bool $operational = false, ?User $actor = null, ?string $key = null,
        ?int $petId = null, string $result = 'success'): ?ActivityLog
    {
        if (! $this->available()) return null; // Reduced-schema tests and migration commands.
        if (! isset(self::LABELS[$action])) throw new \InvalidArgumentException('Unknown activity action.');
        $attributes = $this->attributes($action, $area, $subjectType, $subjectId, $context, $changes, $operational, $actor, $key, $petId, $result);
        return $key === null ? ActivityLog::create($attributes)
            : ActivityLog::firstOrCreate(['event_key' => $attributes['event_key']], $attributes);
    }

    /** Expiry already locks at most 200 active rows; keep its history write batched too. */
    public function recordExpirations(\Illuminate\Database\Eloquent\Collection $registrations, string $area): void
    {
        if (! $this->available() || $registrations->isEmpty()) return;
        $rows = [];
        foreach ($registrations as $registration) {
            $row = $this->attributes('pre_registration_no_show', $area,
                $area === 'grooming' ? 'grooming_visit' : 'clinic_visit', (string) $registration->getKey(),
                $registration->booking_reference ?? $registration->appointment_reference ?? 'Online Registration',
                ['status' => ['before' => 'waiting_to_arrive', 'after' => 'no_show']], true, null, 'no_show:'.$registration->getTable().':'.$registration->getKey(), $registration->pet_id, 'success');
            $row['changes'] = json_encode($row['changes'], JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }
        ActivityLog::insert($rows);
    }

    private function attributes(string $action, string $area, string $subjectType, string $subjectId, string $context,
        array $changes, bool $operational, ?User $actor, ?string $key, ?int $petId, string $result): array
    {
        $role = $actor ? match ($actor->role) {
            'admin' => 'Admin',
            'staff' => match ($actor->staff_subrole) {
                'grooming_receptionist' => 'Grooming Receptionist', 'clinic_receptionist' => 'Clinic Receptionist',
                'veterinarian' => 'Veterinarian', default => 'Staff',
            }, default => 'Customer',
        } : null;
        $display = ! $actor ? 'System' : ($actor->role === 'admin' ? $actor->username
            : trim($actor->first_name).' · '.$role);
        return [
            'actor_id' => $actor?->getKey(), 'actor_display' => $display ?: 'Admin', 'actor_role' => $role,
            'actor_identity' => ! $actor ? null : ($actor->role === 'admin' ? (trim($actor->first_name.' '.$actor->last_name) ?: null)
                : trim($actor->first_name.' '.$actor->last_name).' · '.$actor->username.' · #'.$actor->getKey()),
            'action' => $action, 'area' => $area, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'context' => mb_substr($context, 0, 255), 'pet_id' => $petId,
            'changes' => array_intersect_key($changes, array_flip(self::SAFE_CHANGES)),
            'operational' => $operational, 'result' => $result, 'occurred_at' => now(),
            'event_key' => $key !== null ? hash('sha256', $key) : null,
        ];
    }
}
