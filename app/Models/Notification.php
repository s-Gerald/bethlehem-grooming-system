<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Notification extends Model
{
    public const CATEGORY_GROOMING = 'grooming';

    public const CATEGORY_CLINIC = 'clinic';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_CANCELLATIONS = 'cancellations';

    public const TYPE_CLINIC_BOOKED = 'clinic_booked';

    public const TYPE_CLINIC_WALK_IN = 'clinic_walk_in';

    public const TYPE_CLINIC_PAYMENT_DUE = 'clinic_payment_due';

    public const TYPE_CLINIC_PAYMENT_CONFIRMED = 'clinic_payment_confirmed';

    public const TYPE_CLINIC_CANCELLED = 'clinic_cancelled';

    public const TYPE_NO_SHOW = 'no_show';

    public const TYPE_CLINIC_NO_SHOW = 'clinic_no_show';

    protected $table = 'notifications';

    protected $primaryKey = 'notification_id';

    public $timestamps = false;

    protected $fillable = [
        'type',
        'booking_id',
        'clinic_appointment_id',
        'message',
        'is_read',
        'created_at',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function categoryTypes(): array
    {
        return [
            self::CATEGORY_GROOMING => [
                'booked',
                'rescheduled',
                self::TYPE_NO_SHOW,
                'booking_confirmed',
                'status_update',
                'queue_update',
                'walk_in_registered',
            ],
            self::CATEGORY_CLINIC => [
                self::TYPE_CLINIC_BOOKED,
                self::TYPE_CLINIC_WALK_IN,
                self::TYPE_CLINIC_NO_SHOW,
            ],
            self::CATEGORY_PAYMENTS => [
                'payment_due',
                'payment_confirmed',
                self::TYPE_CLINIC_PAYMENT_DUE,
                self::TYPE_CLINIC_PAYMENT_CONFIRMED,
            ],
            self::CATEGORY_CANCELLATIONS => [
                'cancelled',
                self::TYPE_CLINIC_CANCELLED,
            ],
        ];
    }

    public static function categoryForType(?string $type): string
    {
        foreach (self::categoryTypes() as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return self::CATEGORY_GROOMING;
    }

    /** Service and activity are separate dimensions; legacy categories remain unchanged. */
    public static function serviceTypes(): array
    {
        $types = array_merge(...array_values(self::categoryTypes()));

        return [
            'grooming' => array_values(array_filter($types, fn ($type) => ! str_starts_with($type, 'clinic_'))),
            'clinic' => array_values(array_filter($types, fn ($type) => str_starts_with($type, 'clinic_'))),
        ];
    }

    public static function activities(): array
    {
        return [
            'pre_registrations' => ['label' => 'Online Registrations', 'types' => ['booked', self::TYPE_CLINIC_BOOKED]],
            'walk_ins' => ['label' => 'Walk-ins', 'types' => ['walk_in_registered', self::TYPE_CLINIC_WALK_IN], 'selectable' => false],
            'schedule_changes' => ['label' => 'Schedule changes', 'types' => ['rescheduled']],
            'status_updates' => ['label' => 'Queue / status updates', 'types' => ['booking_confirmed', 'status_update', 'queue_update'], 'selectable' => false],
            'payments' => ['label' => 'Payments', 'types' => self::categoryTypes()[self::CATEGORY_PAYMENTS]],
            'cancellations' => ['label' => 'Cancellations', 'types' => self::categoryTypes()[self::CATEGORY_CANCELLATIONS]],
            'expired' => ['label' => 'No show', 'types' => [self::TYPE_NO_SHOW, self::TYPE_CLINIC_NO_SHOW]],
        ];
    }

    public static function activityForType(?string $type): ?string
    {
        foreach (self::activities() as $activity => $definition) {
            if (in_array($type, $definition['types'], true)) return $activity;
        }

        return null;
    }

    /** Relations are loaded in batches by expiry and the notification controller. */
    public static function noShowMessage(Booking|ClinicAppointment|null $registration, string $service): string
    {
        $owner = $registration?->user
            ? trim($registration->user->first_name.' '.$registration->user->last_name)
            : trim(($registration?->walkin?->fname ?? '').' '.($registration?->walkin?->lname ?? ''));
        $pets = $registration instanceof Booking
            ? $registration->bookingPets->pluck('pet.pet_name')->filter()->unique()->values()
            : collect([$registration?->pet?->pet_name])->filter()->values();
        $identity = implode(' · ', array_filter([$owner, $pets->take(3)->implode(', ').($pets->count() > 3 ? ' +'.($pets->count() - 3) : '')]));
        $selectedDate = $registration instanceof Booking ? $registration->booking_date : $registration?->appointment_date;
        $day = $selectedDate ? Carbon::parse($selectedDate) : null;
        $date = $day ? ' for '.$day->format($day->year === now()->year ? 'M j' : 'M j, Y') : '';

        return 'No show — '.ucfirst($service).($identity !== '' ? ': '.$identity : '').'. Online Registration'.$date.' · No check-in recorded';
    }

    public static function createForClinic(
        ClinicAppointment $appointment,
        string $type,
        string $message,
    ): ?self {
        if (
            ! Schema::hasTable('notifications')
            || ! Schema::hasColumn('notifications', 'clinic_appointment_id')
        ) {
            return null;
        }

        return self::create([
            'type' => $type,
            'booking_id' => null,
            'clinic_appointment_id' => $appointment->id,
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
