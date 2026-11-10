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

    public function cancelWithAudit(array $audit): void
    {
        $this->update($audit + ['status' => 'cancelled', 'cancel_count' => ($this->cancel_count ?? 0) + 1]);
        Notification::create(['type' => 'cancelled', 'booking_id' => $this->booking_id,
            'message' => "Grooming visit {$this->booking_reference} was cancelled by clinic staff.",
            'is_read' => false, 'created_at' => now()]);
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

    /** SQL equivalent of hasSuccessfulPayment(), including legacy paid flags. */
    public function scopeWithSuccessfulPayment(Builder $query): Builder
    {
        return $query->where(fn (Builder $paid) => $paid->where('paid', true)
            ->orWhereHas('payments', fn (Builder $payment) => $payment->where('payment_status', 'paid')));
    }

    public function scopeFullyGroomed(Builder $query): Builder
    {
        return $query->whereHas('bookingPets', fn (Builder $pet) => $pet
            ->where('grooming_state', BookingPet::GROOMING_STATE_FINISHED)->whereNotNull('grooming_end_time'))
            ->whereDoesntHave('bookingPets', fn (Builder $pet) => $pet
                ->where(function (Builder $unfinished) {
                    $unfinished->whereNull('grooming_state')
                        ->orWhereNotIn('grooming_state', [BookingPet::GROOMING_STATE_FINISHED, BookingPet::GROOMING_STATE_CANCELLED])
                        ->orWhere(fn (Builder $finished) => $finished->where('grooming_state', BookingPet::GROOMING_STATE_FINISHED)
                            ->whereNull('grooming_end_time'));
                }));
    }

    /** Preserve terminal legacy outcomes; service completion does not require pickup. */
    public function scopeGroomingHistory(Builder $query, array $terminalStatuses = ['archived', 'cancelled']): Builder
    {
        return $query->where(fn (Builder $history) => $history->whereIn('status', $terminalStatuses)
            ->orWhere(fn (Builder $completed) => $completed->withSuccessfulPayment()->fullyGroomed()));
    }

    public function scopeWithGroomingHistoryTimestamp(Builder $query, ?string $date = null): Builder
    {
        $timestamp = "CASE
            WHEN status IN ('cancelled', 'expired', 'no_show') OR cancel_count > 0 OR TRIM(COALESCE(cancellation_reason, '')) <> ''
                THEN COALESCE(cancelled_at, booking_date)
            ELSE COALESCE(grooming_finished_at, booking_date)
            END";

        return $query->select('bookings.*')->selectRaw("{$timestamp} AS history_at")
            ->when($date, fn (Builder $history) => $history->whereRaw("DATE({$timestamp}) = ?", [$date]));
    }

    public function groomingOwnerName(): string
    {
        $owner = $this->user ?? $this->walkin?->user ?? $this->walkin?->unregisteredCustomer;

        return $owner ? trim(($owner->first_name ?? '').' '.($owner->last_name ?? ''))
            : trim(($this->walkin?->fname ?? '').' '.($this->walkin?->lname ?? ''));
    }

    /** Match the same owner precedence used for display, including full names. */
    public function scopeWhereGroomingOwnerMatches(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY);
        $match = function (Builder $owner, array $columns = ['first_name', 'last_name']) use ($terms) {
            foreach ($terms as $term) {
                $owner->where(fn (Builder $name) => $name->whereRaw("LOWER({$columns[0]}) LIKE ?", ["%{$term}%"])
                    ->orWhereRaw("LOWER({$columns[1]}) LIKE ?", ["%{$term}%"]));
            }
        };

        return $query->where(fn (Builder $booking) => $booking->whereHas('user', $match)
            ->orWhere(fn (Builder $walkinBooking) => $walkinBooking->whereDoesntHave('user')
                ->whereHas('walkin', fn (Builder $walkin) => $walkin->whereHas('user', $match)
                    ->orWhere(fn (Builder $unregistered) => $unregistered->whereDoesntHave('user')
                        ->where(fn (Builder $identity) => $identity->whereHas('unregisteredCustomer', $match)
                            ->orWhere(fn (Builder $legacy) => $legacy->whereDoesntHave('unregisteredCustomer')
                                ->where(fn (Builder $name) => $match($name, ['fname', 'lname']))))))));
    }

    public function hasCancellableState(): bool
    {
        if ($this->status !== 'waiting_to_arrive' || $this->grooming_started_at || $this->grooming_finished_at
            || $this->hasSuccessfulPayment()) {
            return false;
        }

        $started = fn ($pet) => ! in_array($pet->grooming_state, [BookingPet::GROOMING_STATE_NOT_STARTED, BookingPet::GROOMING_STATE_CANCELLED], true)
            || $pet->grooming_start_time !== null || $pet->grooming_end_time !== null;

        return $this->relationLoaded('bookingPets')
            ? ! $this->bookingPets->contains($started)
            : ! $this->bookingPets()->where(fn ($pet) => $pet
                ->whereNotIn('grooming_state', [BookingPet::GROOMING_STATE_NOT_STARTED, BookingPet::GROOMING_STATE_CANCELLED])
                ->orWhereNotNull('grooming_start_time')->orWhereNotNull('grooming_end_time'))->exists();
    }

}
