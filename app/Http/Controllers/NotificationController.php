<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ClinicAppointment;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    private const STATUSES = ['all', 'unread'];

    private const CATEGORIES = [
        'all',
        Notification::CATEGORY_GROOMING,
        Notification::CATEGORY_CLINIC,
        Notification::CATEGORY_PAYMENTS,
        Notification::CATEGORY_CANCELLATIONS,
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'service' => ['sometimes', Rule::in(['all', ...array_keys(Notification::serviceTypes())])],
            'activity' => ['sometimes', Rule::in(['all', ...array_keys(Notification::activities())])],
            'mode' => ['sometimes', Rule::in(['count', 'dropdown', 'full'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $status = $filters['status'] ?? 'all';
        $category = $filters['category'] ?? 'all';
        $mode = $filters['mode'] ?? 'dropdown';
        $service = $filters['service'] ?? 'all';
        $activity = $filters['activity'] ?? 'all';
        if ($mode === 'count') {
            return response()->json(['success' => true, 'unread_count' => $this->unreadCount()]);
        }
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? ($mode === 'full' ? 10 : 30));

        $query = Notification::query()->with([
            'booking.user',
            'booking.walkin',
            'booking.timeWindow',
            'booking.bookingPets.pet',
            'clinicAppointment.user',
            'clinicAppointment.walkin',
            'clinicAppointment.pet',
            'clinicAppointment.timeWindow',
        ]);

        if ($status === 'unread') {
            $query->where('is_read', false);
        }

        if ($category !== 'all') {
            $query->whereIn('type', Notification::categoryTypes()[$category]);
        }

        if ($service !== 'all') {
            $query->whereIn('type', Notification::serviceTypes()[$service]);
        }
        if ($activity !== 'all') {
            $query->whereIn('type', Notification::activities()[$activity]['types']);
        }

        if ($mode === 'dropdown' && $status === 'all') {
            $query->orderBy('is_read');
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount(),
            'activity_options' => collect(Notification::activities())->map(
                fn (array $definition, string $value) => ['value' => $value, 'label' => $definition['label']],
            )->values(),
            'notifications' => collect($paginator->items())
                ->map(fn (Notification $notification) => $this->serialize($notification))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'filters' => [
                'status' => $status,
                'category' => $category,
                'service' => $service,
                'activity' => $activity,
            ],
        ]);
    }

    public function markRead($id)
    {
        $notification = Notification::find($id);

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => $this->unreadCount(),
        ]);
    }

    public function markAllRead()
    {
        Notification::query()->where('is_read', false)->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'unread_count' => $this->unreadCount(),
        ]);
    }

    private function unreadCount(): int
    {
        return Notification::query()->where('is_read', false)->count();
    }

    /** @return array<string, mixed> */
    private function serialize(Notification $notification): array
    {
        $content = $this->contentFor($notification);

        return [
            'notification_id' => $notification->notification_id,
            'type' => $notification->type,
            'category' => Notification::categoryForType($notification->type),
            'service' => str_starts_with((string) $notification->type, 'clinic_') ? 'clinic' : 'grooming',
            'activity' => Notification::activityForType($notification->type),
            'icon' => $this->iconForType($notification->type),
            'title' => $content['title'],
            'message' => $content['message'],
            'message_parts' => $this->emphasizedParts(
                $content['message'],
                $content['important_terms'],
            ),
            'context' => $content['context'],
            'context_parts' => $this->emphasizedParts(
                $content['context'],
                $content['important_terms'],
            ),
            'is_read' => (bool) $notification->is_read,
            'created_at' => $notification->created_at,
            'booking_reference' => $notification->booking?->booking_reference,
            'clinic_appointment_reference' => $notification
                ->clinicAppointment?->appointment_reference,
        ];
    }

    /**
     * @return array{title: string, message: string, context: ?string, important_terms: array<int, string>}
     */
    private function contentFor(Notification $notification): array
    {
        $booking = $notification->booking;
        $clinicAppointment = $notification->clinicAppointment;
        $storedMessage = $this->normalizeStoredMessage($notification->message);

        return match ($notification->type) {
            'booked' => $this->groomingBookedContent($booking, $storedMessage),
            'rescheduled' => $this->groomingRescheduledContent($booking, $storedMessage),
            'cancelled' => $this->groomingCancelledContent($booking, $storedMessage),
            'payment_due' => $this->groomingPaymentContent($booking, $storedMessage),
            'payment_confirmed' => $this->groomingPaymentReceivedContent(
                $booking,
                $storedMessage,
            ),
            'no_show' => $this->legacyExpiredContent($booking),
            Notification::TYPE_CLINIC_BOOKED => $this->clinicBookedContent(
                $clinicAppointment,
                false,
                $storedMessage,
            ),
            Notification::TYPE_CLINIC_WALK_IN => $this->clinicBookedContent(
                $clinicAppointment,
                true,
                $storedMessage,
            ),
            Notification::TYPE_CLINIC_PAYMENT_DUE => $this->clinicPaymentContent(
                $clinicAppointment,
                $storedMessage,
            ),
            Notification::TYPE_CLINIC_PAYMENT_CONFIRMED => $this->clinicPaymentReceivedContent(
                $clinicAppointment,
                $storedMessage,
            ),
            Notification::TYPE_CLINIC_CANCELLED => $this->clinicCancelledContent(
                $clinicAppointment,
                $storedMessage,
            ),
            default => $this->content(
                Str::headline((string) $notification->type),
                $storedMessage,
                null,
                [
                    $booking?->booking_reference,
                    $clinicAppointment?->appointment_reference,
                ],
            ),
        };
    }

    private function groomingBookedContent(?Booking $booking, string $fallback): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $schedule = $this->bookingSchedule($booking);
        $message = $fallback !== ''
            ? $this->normalizeStoredScheduleMessage($fallback)
            : ($reference ? 'New pre-registration '.$reference
                .($owner ? " by {$owner}" : '')
                .($schedule ? " on {$schedule}" : '').'.'
                : $fallback);

        return $this->content(
            'New pre-registration',
            $message,
            null,
            [$reference, $owner],
        );
    }

    private function groomingRescheduledContent(?Booking $booking, string $fallback): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $schedule = $this->bookingSchedule($booking);
        $message = $fallback !== ''
            ? $this->normalizeStoredScheduleMessage($fallback)
            : ($reference ? 'Pre-registration '.$reference.' was rescheduled'
                .($owner ? " by {$owner}" : '')
                .($schedule ? " to {$schedule}" : '').'.'
                : $fallback);

        return $this->content(
            'Grooming pre-registration rescheduled',
            $message,
            null,
            [$reference, $owner],
        );
    }

    private function groomingCancelledContent(?Booking $booking, string $fallback): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $cancelledByClinic = Str::contains(Str::lower($fallback), 'clinic staff');
        $actor = $cancelledByClinic ? 'clinic staff' : $owner;
        $message = $reference
            ? "Pre-registration cancelled: {$reference}"
                .($actor ? " by {$actor}." : '.')
            : $fallback;
        $reason = trim((string) $booking?->cancellation_reason);

        if ($reason === '' || Str::lower($reason) === 'cancelled by clinic staff.') {
            $reason = null;
        }

        return $this->content(
            'Grooming pre-registration cancelled',
            $message,
            $reason ? "Reason: {$reason}" : null,
            [$reference, $actor],
        );
    }

    private function groomingPaymentContent(?Booking $booking, string $fallback): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $petCount = count($this->bookingPetNames($booking));
        $readyText = $petCount > 1
            ? 'Pets are ready - please collect payment.'
            : 'Pet is ready - please collect payment.';
        $message = $owner
            ? "Grooming done for {$owner}. {$readyText}"
            : ($reference ? "Grooming done for booking {$reference}. {$readyText}" : $fallback);

        return $this->content(
            'Grooming done',
            $message,
            null,
            [$owner, $reference],
        );
    }

    private function groomingPaymentReceivedContent(?Booking $booking, string $fallback): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $message = $reference
            ? 'Payment received'.($owner ? " from {$owner}" : '')." for booking {$reference}."
            : $fallback;

        return $this->content(
            'Payment received',
            $message,
            null,
            [$owner, $reference],
        );
    }

    private function legacyExpiredContent(?Booking $booking): array
    {
        $reference = $booking?->booking_reference;
        $owner = $this->bookingOwnerName($booking);
        $message = $reference
            ? "Pre-registration {$reference}"
                .($owner ? " for {$owner}" : '').' was not used on its selected date.'
            : 'This historical pre-registration was not used on its selected date.';

        return $this->content(
            'Pre-registration expired',
            $message,
            null,
            [$reference, $owner],
        );
    }

    private function clinicBookedContent(
        ?ClinicAppointment $appointment,
        bool $walkIn,
        string $fallback,
    ): array {
        $reference = $appointment?->appointment_reference;
        $owner = $this->clinicOwnerName($appointment);
        $pet = trim((string) $appointment?->pet?->pet_name);
        $schedule = $this->clinicSchedule($appointment);
        $message = $reference
            ? ($walkIn ? 'New clinic walk-in ' : 'New clinic pre-registration ').$reference
                .($owner ? " for {$owner}" : '')
                .($pet ? " and {$pet}" : '')
                .($schedule ? " on {$schedule}" : '').'.'
            : $fallback;

        return $this->content(
            $walkIn ? 'New clinic walk-in' : 'New clinic pre-registration',
            $message,
            null,
            [$reference, $owner, $pet],
        );
    }

    private function clinicPaymentContent(?ClinicAppointment $appointment, string $fallback): array
    {
        $reference = $appointment?->appointment_reference;
        $owner = $this->clinicOwnerName($appointment);
        $pet = trim((string) $appointment?->pet?->pet_name);
        $message = $reference
            ? 'Clinic visit '.$reference
                .($owner ? " for {$owner}" : '')
                .($pet ? " and {$pet}" : '').' is ready for payment.'
            : $fallback;

        return $this->content(
            'Clinic visit ready for payment',
            $message,
            null,
            [$reference, $owner, $pet],
        );
    }

    private function clinicPaymentReceivedContent(
        ?ClinicAppointment $appointment,
        string $fallback,
    ): array {
        $reference = $appointment?->appointment_reference;
        $owner = $this->clinicOwnerName($appointment);
        $message = $reference
            ? 'Payment received'.($owner ? " from {$owner}" : '')
                ." for clinic visit {$reference}."
            : $fallback;

        return $this->content(
            'Payment received',
            $message,
            null,
            [$owner, $reference],
        );
    }

    private function clinicCancelledContent(?ClinicAppointment $appointment, string $fallback): array
    {
        $reference = $appointment?->appointment_reference;
        $owner = $this->clinicOwnerName($appointment);
        $pet = trim((string) $appointment?->pet?->pet_name);
        $message = $reference
            ? "Clinic visit cancelled: {$reference}"
                .($owner ? " for {$owner}" : '')
                .($pet ? " and {$pet}" : '').' by clinic staff.'
            : $fallback;

        return $this->content(
            'Clinic visit cancelled',
            $message,
            null,
            [$reference, $owner, $pet],
        );
    }

    /**
     * @param  array<int, string|null>  $importantTerms
     * @return array{title: string, message: string, context: ?string, important_terms: array<int, string>}
     */
    private function content(
        string $title,
        string $message,
        ?string $context,
        array $importantTerms,
    ): array {
        return [
            'title' => $title,
            'message' => $message,
            'context' => filled($context) ? $context : null,
            'important_terms' => collect($importantTerms)
                ->map(fn ($term) => trim((string) $term))
                ->filter()
                ->unique(fn (string $term) => Str::lower($term))
                ->sortByDesc(fn (string $term) => mb_strlen($term))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, string>  $importantTerms
     * @return array<int, array{text: string, emphasized: bool}>
     */
    private function emphasizedParts(?string $text, array $importantTerms): array
    {
        if (! filled($text)) {
            return [];
        }

        if ($importantTerms === []) {
            return [['text' => (string) $text, 'emphasized' => false]];
        }

        $pattern = '/('.implode('|', array_map(
            fn (string $term) => preg_quote($term, '/'),
            $importantTerms,
        )).')/iu';
        $parts = preg_split($pattern, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return collect($parts ?: [])
            ->filter(fn (string $part) => $part !== '')
            ->map(fn (string $part) => [
                'text' => $part,
                'emphasized' => collect($importantTerms)->contains(
                    fn (string $term) => Str::lower($term) === Str::lower($part),
                ),
            ])
            ->values()
            ->all();
    }

    private function bookingOwnerName(?Booking $booking): string
    {
        if ($booking?->user) {
            return trim($booking->user->first_name.' '.$booking->user->last_name);
        }

        return trim(($booking?->walkin?->fname ?? '').' '.($booking?->walkin?->lname ?? ''));
    }

    private function clinicOwnerName(?ClinicAppointment $appointment): string
    {
        if ($appointment?->user) {
            return trim($appointment->user->first_name.' '.$appointment->user->last_name);
        }

        return trim(($appointment?->walkin?->fname ?? '').' '.($appointment?->walkin?->lname ?? ''));
    }

    private function iconForType(?string $type): string
    {
        return match ($type) {
            'booked', 'rescheduled' => 'calendar-days',
            'payment_due' => 'circle-check',
            'payment_confirmed', Notification::TYPE_CLINIC_PAYMENT_CONFIRMED => 'credit-card',
            'cancelled', Notification::TYPE_CLINIC_CANCELLED => 'circle-x',
            'no_show' => 'history',
            Notification::TYPE_CLINIC_BOOKED,
            Notification::TYPE_CLINIC_WALK_IN => 'stethoscope',
            Notification::TYPE_CLINIC_PAYMENT_DUE => 'circle-check',
            default => 'bell',
        };
    }

    /** @return array<int, string> */
    private function bookingPetNames(?Booking $booking): array
    {
        if (! $booking) {
            return [];
        }

        return $booking->bookingPets
            ->pluck('pet.pet_name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function bookingSchedule(?Booking $booking): ?string
    {
        if (! $booking?->booking_date) {
            return null;
        }

        $date = $this->notificationDateLabel($booking->booking_date);
        $window = $booking->preferredArrivalWindow()?->displayLabel();

        return $window ? "{$date} at {$window}" : $date;
    }

    private function clinicSchedule(?ClinicAppointment $appointment): ?string
    {
        if (! $appointment?->appointment_date) {
            return null;
        }

        $date = $this->notificationDateLabel($appointment->appointment_date);
        $window = $appointment->timeWindow?->displayLabel();

        return $window ? "{$date} at {$window}" : $date;
    }

    private function notificationDateLabel(mixed $value): string
    {
        $date = Carbon::parse($value);

        return $date->year === now()->year
            ? $date->format('M j')
            : $date->format('M j, Y');
    }

    private function normalizeStoredMessage(?string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $message));
    }

    private function normalizeStoredScheduleMessage(string $message): string
    {
        $message = preg_replace_callback(
            '/\b\d{4}-\d{2}-\d{2}\b/',
            fn (array $matches) => $this->notificationDateLabel($matches[0]),
            $message,
        ) ?? $message;

        return preg_replace(
            '/\bNew Pre-registration\b/i',
            'New pre-registration',
            $message,
        ) ?? $message;
    }
}
