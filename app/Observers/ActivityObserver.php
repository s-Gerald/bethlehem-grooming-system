<?php

namespace App\Observers;

use App\Models\{Booking, BookingPet, ClinicAppointment, ClinicRecord, ClinicService, ClinicSetting, InventoryItem, Payment, Pet, PosTransaction, Service, UnregisteredCustomer, User, VaccinationRecord};
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;

/** Only meaningful allowlisted transitions, never raw model serialization. */
class ActivityObserver
{
    public function created(Model $model): void
    {
        $this->capture($model, true);
    }

    public function updated(Model $model): void
    {
        $this->capture($model, false);
    }

    public function deleted(Model $model): void
    {
        // These deletions already run inside their business service transactions.
        if ($model instanceof \App\Models\ClinicAttachment || $model instanceof \App\Models\ClinicCharge) {
            $action = $model instanceof \App\Models\ClinicCharge ? 'clinic_charge_updated' : 'clinical_record_updated';
            app(ActivityRecorder::class)->record($action, 'clinic', $model->getTable(), (string) $model->getKey(),
                'Clinic record #'.$model->getKey(), [], false, auth()->user());
        }
    }

    private function capture(Model $model, bool $created): void
    {
        $recorder = app(ActivityRecorder::class);
        if (! $recorder->available()) return;
        $changes = [];
        foreach ($created ? [] : $model->getChanges() as $field => $value) {
            if (in_array($field, ActivityRecorder::SAFE_CHANGES, true)) {
                $changes[$field] = ['before' => $model->getRawOriginal($field), 'after' => $value];
            }
        }
        $actor = auth()->user();
        $area = 'system'; $action = null; $operational = false; $key = null;
        $subject = match (true) {
            $model instanceof Booking => 'grooming_visit', $model instanceof BookingPet => 'grooming_pet',
            $model instanceof ClinicAppointment => 'clinic_visit', $model instanceof User => 'account',
            $model instanceof Pet => 'pet', $model instanceof InventoryItem => 'inventory',
            $model instanceof Service, $model instanceof ClinicService => 'service',
            default => $model->getTable(),
        };
        $context = $model->booking_reference ?? $model->appointment_reference ?? $model->pet_name
            ?? $model->item_name ?? $model->service_name ?? $model->name ?? ucfirst(str_replace('_', ' ', $subject));
        $petId = $model instanceof Pet ? $model->getKey() : $model->getAttribute('pet_id');

        if ($model instanceof Booking || $model instanceof ClinicAppointment) {
            $clinic = $model instanceof ClinicAppointment;
            $area = $clinic ? 'clinic' : 'grooming';
            $walkin = $clinic ? $model->appointment_type === 'walk_in' : $model->booking_type === 'walk_in';
            if ($created) {
                $action = $model->status === 'checked_in' ? 'checked_in' : ($walkin ? 'walk_in_registered' : 'pre_registration_submitted');
            } elseif ($model->wasChanged('status')) {
                $action = match ($model->status) {
                    'checked_in' => ($model->getRawOriginal('status') === 'in_progress' ? null : 'checked_in'),
                    'in_consultation' => 'consultation_started',
                    'for_payment' => $clinic ? 'consultation_completed' : null,
                    'cancelled' => $model->getRawOriginal('status') === 'waiting_to_arrive' || $model->getRawOriginal('status') === 'pending'
                        ? 'pre_registration_cancelled' : 'queue_status_changed',
                    'expired', 'no_show' => 'pre_registration_expired',
                    'pending', 'waiting_to_arrive' => 'check_in_reversed',
                    default => null,
                };
            } elseif ($model->wasChanged($clinic ? ['appointment_date', 'window_id'] : ['booking_date', 'window_id', 'arrival_end_time'])) {
                $action = 'pre_registration_updated';
            }
            if ($clinic && ! $created && $model->wasChanged('consultation_finished_at') && $model->consultation_finished_at) {
                $action = 'consultation_completed';
            }
            $operational = in_array($action, ['checked_in', 'walk_in_registered', 'pre_registration_submitted', 'pre_registration_updated',
                'pre_registration_cancelled', 'pre_registration_expired', 'check_in_reversed', 'queue_status_changed',
                'consultation_started', 'consultation_completed', 'grooming_corrected'], true);
            // The settlement's recorded actor is authoritative, including null for automatic payments.
            if ($clinic && $model->wasChanged('paid') && $model->paid) {
                $this->payment($model, $area, $subject, $context, $model->gateway_payment_id ? null : $model->paid_by_user_id, $petId);
            }
        } elseif ($model instanceof BookingPet) {
            $area = 'grooming'; $context = 'Grooming · Pet #'.$model->pet_id;
            if (! $created && $model->wasChanged('grooming_state')) {
                $action = match ($model->grooming_state) {
                    'in_progress' => 'grooming_started', 'finished' => 'grooming_completed',
                    'not_started', 'cancelled' => 'grooming_corrected', default => null,
                };
                $operational = true;
            }
        } elseif ($model instanceof Payment) {
            $area = 'grooming'; $context = 'Grooming payment #'.$model->getKey();
            if ($model->payment_status === 'paid' && ($created || $model->wasChanged('payment_status'))) {
                $this->payment($model, $area, 'payment', $context, $model->gateway_payment_id ? null : $model->processed_by, null);
            } elseif (! $created && $model->wasChanged('payment_status')) $action = 'payment_status_updated';
        } elseif ($model instanceof PosTransaction) {
            if ($created) $this->payment($model, 'system', 'product_sale', PosTransaction::referenceFor($model->getKey()),
                $model->gateway_payment_id ? null : $model->cashier_id, null);
        } elseif ($model instanceof InventoryItem) {
            $action = $created ? 'inventory_created' : ($model->wasChanged('quantity_on_hand') ? 'inventory_stock_adjusted'
                : ($model->wasChanged('is_active') ? ($model->is_active ? 'inventory_restored' : 'inventory_archived')
                : ($model->wasChanged($model->getFillable()) ? 'inventory_updated' : null)));
        } elseif ($model instanceof Service || $model instanceof ClinicService) {
            $area = $model instanceof Service ? 'grooming' : 'clinic';
            $action = $created ? 'service_created' : ($model->wasChanged('is_active') ? ($model->is_active ? 'service_activated' : 'service_deactivated')
                : ($model->wasChanged(['base_price', 'price', 'min_price', 'max_price', 'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max', 'tiers', 'range_price_maximums'])
                    ? 'service_price_updated' : ($model->wasChanged($model->getFillable()) ? 'service_updated' : null)));
        } elseif ($model instanceof Pet || $model instanceof UnregisteredCustomer) {
            $prefix = $model instanceof Pet ? 'pet' : 'customer';
            $context = $model instanceof Pet ? $model->pet_name : trim($model->first_name.' '.$model->last_name);
            $action = $created ? $prefix.'_registered' : ($model->wasChanged('is_archived')
                ? $prefix.($model->is_archived ? '_archived' : '_restored') : ($model->wasChanged($model->getFillable()) ? $prefix.'_updated' : null));
        } elseif ($model instanceof User) {
            $context = $model->role === 'admin' ? $model->username : trim($model->first_name.' '.$model->last_name);
            if ($created) {
                $action = $model->role === 'staff' ? 'staff_created' : ($model->role === 'customer' && $model->email_verified_at ? 'customer_registered' : null);
            } elseif ($model->role === 'customer') {
                $action = $model->wasChanged('email_verified_at') && $model->email_verified_at ? 'customer_registered'
                    : ($model->wasChanged('is_archived') ? ($model->is_archived ? 'customer_archived' : 'customer_restored')
                    : ($model->wasChanged(['first_name', 'last_name', 'username', 'phone', 'email', 'is_active']) ? 'customer_updated' : null));
            } else {
                $action = $model->wasChanged('password_hash') ? 'password_changed'
                    : ($model->wasChanged('email') ? 'email_changed'
                    : ($model->wasChanged(['role', 'staff_type', 'staff_subrole']) ? 'staff_role_changed'
                    : ($model->wasChanged('is_active') ? ($model->is_active ? 'staff_activated' : 'staff_deactivated')
                    : ($changes ? 'staff_updated' : null))));
                // Public password setup/reset/recovery acts on the verified account itself.
                if (! $actor && in_array($action, ['password_changed', 'email_changed'], true)) $actor = $model;
            }
        } elseif ($model instanceof \App\Models\BookingService) {
            $area = 'grooming'; $context = $model->service_name_snapshot ?: 'Grooming service';
            $action = ! $created && $model->wasChanged(['price_at_booking', 'price_min_at_booking', 'price_max_at_booking', 'service_id', 'addon_id'])
                ? 'grooming_service_updated' : null;
        } elseif ($model instanceof \App\Models\ClinicCharge) {
            $area = 'clinic'; $context = 'Clinic charges #'.$model->clinic_appointment_id;
            $action = $created || $model->wasChanged(['quantity', 'unit_price', 'amount']) ? 'clinic_charge_updated' : null;
        } elseif ($model instanceof ClinicRecord || $model instanceof VaccinationRecord
            || $model instanceof \App\Models\ClinicVital || $model instanceof \App\Models\ClinicCaseService
            || $model instanceof \App\Models\ClinicConfinement || $model instanceof \App\Models\ClinicConfinementObservation
            || $model instanceof \App\Models\ClinicAttachment) {
            $area = 'clinic'; $changes = []; // Record the action, never copy medical content.
            $action = $created || array_diff(array_keys($model->getChanges()), ['updated_at'])
                ? ($model instanceof VaccinationRecord || $model instanceof \App\Models\ClinicCaseService ? 'treatment_updated' : 'clinical_record_updated') : null;
            $context = 'Clinical record #'.$model->getKey();
        } elseif ($model instanceof ClinicSetting || $model instanceof \App\Models\QrPaymentSettings
            || $model instanceof \App\Models\ClinicClosure || $model instanceof \App\Models\PetGroomer) {
            $action = (! $created || $model instanceof \App\Models\ClinicClosure || $model instanceof \App\Models\PetGroomer)
                && ($created || array_diff(array_keys($model->getChanges()), ['updated_at'])) ? 'settings_updated' : null;
            $changes = []; $context = 'Clinic and Grooming settings';
        }

        if ($action) $recorder->record($action, $area, $subject, (string) $model->getKey(), $context, $changes,
            $operational, $actor, $key, $petId ? (int) $petId : null);
    }

    private function payment(Model $model, string $area, string $subject, string $context, ?int $actorId, ?int $petId): void
    {
        $actor = $actorId ? (auth()->id() === $actorId ? auth()->user() : User::find($actorId)) : null;
        app(ActivityRecorder::class)->record('payment_recorded', $area, $subject, (string) $model->getKey(), $context,
            ['total_amount' => ['after' => $model->total_amount]], false, $actor,
            $model->getTable().':'.$model->getKey().':paid', $petId);
    }
}
