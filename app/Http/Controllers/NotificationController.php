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
    private function visibleNotifications(): \Illuminate\Database\Eloquent\Builder
    {
        return Notification::query()->when(request()->user()?->isVeterinarian(),
            fn ($query) => $query->whereIn('type', Notification::serviceTypes()['clinic']));
    }

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
            'selected_id' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
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

        $visible = $this->visibleNotifications()->with([
            'booking.user',
            'booking.walkin',
            'booking.timeWindow',
            'booking.bookingPets.pet',
            'clinicAppointment.user',
            'clinicAppointment.walkin',
            'clinicAppointment.pet',
            'clinicAppointment.timeWindow',
        ]);
        $query = clone $visible;

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

        // One scoped primary-key lookup, only when the selected row is outside this page.
        $selected = null;
        if ($mode === 'full' && isset($filters['selected_id'])) {
            $selected = $paginator->getCollection()->firstWhere('notification_id', (int) $filters['selected_id'])
                ?? $visible->find((int) $filters['selected_id']);
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount(),
            'activity_options' => collect(Notification::activities())
                ->filter(fn (array $definition) => $definition['selectable'] ?? true)->map(
                fn (array $definition, string $value) => ['value' => $value, 'label' => $definition['label']],
            )->values(),
            'notifications' => collect($paginator->items())
                ->map(fn (Notification $notification) => $this->serialize($notification))
                ->values(),
            ...($mode === 'full' && isset($filters['selected_id'])
                ? ['selected_notification' => $selected ? $this->serialize($selected) : null] : []),
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
        $notification = $this->visibleNotifications()->find($id);

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
        $this->visibleNotifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'unread_count' => $this->unreadCount(),
        ]);
    }

    private function unreadCount(): int
    {
        return $this->visibleNotifications()->where('is_read', false)->count();
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
            Notification::TYPE_NO_SHOW, Notification::TYPE_CLINIC_NO_SHOW => $this->noShowContent($notification, $storedMessage),
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
            : ($reference ? 'New online registration '.$reference
                .($owner ? " by {$owner}" : '')
                .($schedule ? " on {$schedule}" : '').'.'
                : $fallback);

        return $this->content(
            'New online registration',
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
            : ($reference ? 'Online Registration '.$reference.' was rescheduled'
                .($owner ? " by {$owner}" : '')
                .($schedule ? " to {$schedule}" : '').'.'
                : $fallback);

        return $this->content(
            'Grooming online registration rescheduled',
            $message,
            null,
            [$reference, $owner],
        );
    }

    private function groomingCancelledContent(?Booking $booking, string $fallback): array
    {
        $text = \App\Services\CancellationNotificationContent::forVisit($booking, 'Grooming', $fallback);
        return $this->content($text['title'], $text['admin'], $text['context'], [$booking?->booking_reference]);
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

    private function noShowContent(Notification $notification, string $storedMessage): array
    {
        $service = $notification->type === Notification::TYPE_CLINIC_NO_SHOW ? 'clinic' : 'grooming';
        $registration = $service === 'clinic' ? $notification->clinicAppointment : $notification->booking;
        $message = str_starts_with($storedMessage, 'No show — ') || ! $registration
            ? ($storedMessage ?: Notification::noShowMessage(null, $service))
            : Notification::noShowMessage($registration, $service);

        return $this->content(
            'No show — '.ucfirst($service),
            $message,
            null,
            [$service === 'clinic' ? $this->clinicOwnerName($registration) : $this->bookingOwnerName($registration)],
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
            ? ($walkIn ? 'New clinic walk-in ' : 'New clinic online registration ').$reference
                .($owner ? " for {$owner}" : '')
                .($pet ? " and {$pet}" : '')
                .($schedule ? " on {$schedule}" : '').'.'
            : $fallback;

        return $this->content(
            $walkIn ? 'New clinic walk-in' : 'New clinic online registration',
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
        $text = \App\Services\CancellationNotificationContent::forVisit($appointment, 'Clinic', $fallback);
        return $this->content($text['title'], $text['admin'], $text['context'], [$appointment?->appointment_reference]);
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
            Notification::TYPE_NO_SHOW, Notification::TYPE_CLINIC_NO_SHOW => 'history',
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
        return str_ireplace('pre-registration', 'Online Registration', trim((string) preg_replace('/\s+/', ' ', (string) $message)));
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
            'New online registration',
            $message,
        ) ?? $message;
    }
}
