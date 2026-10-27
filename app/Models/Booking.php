<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Booking extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'bookings';

    protected $primaryKey = 'booking_id';

    public $timestamps = false;

    protected $fillable = [
        'booking_reference',
        'user_id',
        'walkin_id',
        'window_id',
        'arrival_end_time',
        'booking_date',
        'registration_closes_at',
        'number_of_pets',
        'booking_type',
        'status',
        'queue_number',
        'special_notes',
        'internal_staff_note',
        'sedation_consent',
        'sedation_consent_source',
        'sedation_consent_recorded_by',
        'sedation_consent_recorded_at',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'total_amount',
        'reschedule_count',
        'cancel_count',
        'archived_at',
        'dropped_off_at',
        'grooming_started_at',
        'grooming_finished_at',
        'paid',
    ];

    protected $hidden = ['internal_staff_note'];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'sedation_consent' => 'boolean',
        'sedation_consent_recorded_at' => 'datetime',
    ];

    /**
     * Keep cancelled pre-registrations out of operational lists.
     * The cancellation markers remain authoritative even if a legacy record's
     * status was later changed to archived.
     */
    public function scopeNeverCancelled(
        Builder $query,
        bool $hasCancellationAuditColumns = true,
    ): Builder
    {
        $query->where('status', '!=', 'cancelled');

        if ($hasCancellationAuditColumns) {
            $query->where(function (Builder $cancellationCount) {
                $cancellationCount->whereNull('cancel_count')
                    ->orWhere('cancel_count', 0);
            })->where(function (Builder $cancellationReason) {
                $cancellationReason->whereNull('cancellation_reason')
                    ->orWhere('cancellation_reason', '');
            });
        }

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function walkin()
    {
        return $this->belongsTo(Walkin::class, 'walkin_id', 'id');
    }

    public function groomingConsent()
    {
        return $this->hasOne(GroomingConsent::class, 'booking_id', 'booking_id');
    }

    public function timeWindow()
    {
        return $this->belongsTo(TimeWindow::class, 'window_id', 'window_id');
    }

    public function preferredArrivalWindow(): ?TimeWindow
    {
        $window = $this->timeWindow;
        if (! $window || ! $this->arrival_end_time) return $window;

        $window = clone $window;
        $window->end_time = $this->arrival_end_time;
        $start = Carbon::parse($window->start_time);
        $end = Carbon::parse($window->end_time);
        $window->window_label = $start->equalTo($end) ? $start->format('g:i A')
            : $start->format('g:i A').' - '.$end->format('g:i A');
        return $window;
    }

    // Booking has many pets
    public function bookingPets()
    {
        return $this->hasMany(BookingPet::class, 'booking_id', 'booking_id');
    }

    // Booking has many services
    public function bookingServices()
    {
        return $this->hasMany(BookingService::class, 'booking_id', 'booking_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'booking_id', 'booking_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    public function hasSuccessfulPayment(): bool
    {
        return (bool) $this->paid || ($this->relationLoaded('payments')
            ? $this->payments->contains('payment_status', 'paid')
            : $this->payments()->where('payment_status', 'paid')->exists());
    }

}
