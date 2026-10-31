<?php

namespace App\Http\Controllers;

use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CustomerNotificationController extends Controller
{
    // GET /customer/notifications
    public function index(Request $request)
    {
        $userId = $request->user()->user_id;
        app(\App\Services\PreRegistrationExpiry::class)->expire((int) $userId);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = 30;
        $recentFirst = $request->query('sort') === 'recent';
        $unreadOnly = $request->query('status') === 'unread';

        $query = CustomerNotification::where('user_id', $userId)
            ->when($unreadOnly, fn ($query) => $query->where('is_read', 0))
            ->with([
                'booking.bookingPets.pet',
                'booking.timeWindow',
                'pet:pet_id,user_id,pet_name,species',
                ...(Schema::hasTable('clinic_appointments') ? ['clinicAppointment.pet'] : []),
            ])
            ->when(! $recentFirst, fn ($query) => $query->orderBy('is_read', 'asc'))
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize + 1);
        $records = $query->get();
        $hasMore = $records->count() > $pageSize;
        $notifications = $records->take($pageSize)
            ->map(function ($n) {
                $petNames = $this->notificationPetNames($n);
                $petTypes = $this->notificationPetTypes($n, $petNames);
                $updatedPet = $n->type === CustomerNotification::TYPE_PET_INFORMATION_UPDATED
                    && (int) $n->pet?->user_id === (int) $n->user_id
                        ? $n->pet
                        : null;
                $destination = null;

                if (! $destination && $updatedPet) {
                    $destination = $this->petOverviewDestination(
                        $updatedPet->pet_id,
                    );
                }

                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'title' => match ($n->type) {
                        CustomerNotification::TYPE_PET_INFORMATION_UPDATED => 'Pet Information Updated',
                        CustomerNotification::TYPE_GROOMING_PRE_REGISTRATION_EXPIRED => 'Grooming Online Registration expired',
                        CustomerNotification::TYPE_GROOMING_NO_SHOW => 'Grooming Online Registration — No Show',
                        CustomerNotification::TYPE_BOOKING_CANCELLED => $n->pet_id
                            ? ($n->clinic_appointment_id ? 'Clinic Online Registration Cancelled' : 'Grooming Online Registration Cancelled') : null,
                        default => null,
                    },
                    'message' => $this->formatNotificationMessage($n->message),
                    'display_message' => $this->formatCustomerNotificationMessage($n, $petNames),
                    'pet_names' => $petNames,
                    'pet_types' => $petTypes,
                    'is_read' => (bool) $n->is_read,
                    'created_at' => $n->created_at?->toDateTimeString(),
                    'booking_id' => $n->booking_id,
                    'clinic_appointment_id' => $n->clinic_appointment_id,
                    'pet_id' => $updatedPet?->pet_id ?? ($n->type === CustomerNotification::TYPE_BOOKING_CANCELLED ? $n->pet_id : null),
                    'pet_name' => $updatedPet?->pet_name ?? ($n->type === CustomerNotification::TYPE_BOOKING_CANCELLED ? $n->pet?->pet_name : null),
                    'destination' => $destination,
                ];
            });

        $unreadCount = CustomerNotification::where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        // Check for any unread ready_for_pickup notification
        $pickupNotif = CustomerNotification::where('user_id', $userId)
            ->where('type', 'ready_for_pickup')
            ->where('is_read', 0)
            ->with(['booking.bookingPets.pet', 'booking.timeWindow'])
            ->latest('created_at')
            ->first();

        $pickupPetNames = $pickupNotif ? $this->notificationPetNames($pickupNotif) : [];
        $pickupPetTypes = $pickupNotif ? $this->notificationPetTypes($pickupNotif, $pickupPetNames) : [];

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
            'has_more' => $hasMore,
            'pickup_alert' => $pickupNotif ? [
                'id' => $pickupNotif->id,
                'message' => $this->formatNotificationMessage($pickupNotif->message),
                'display_message' => $this->formatCustomerNotificationMessage($pickupNotif, $pickupPetNames),
                'pet_names' => $pickupPetNames,
                'pet_types' => $pickupPetTypes,
                'booking_id' => $pickupNotif->booking_id,
            ] : null,
        ]);
    }

    // PATCH /customer/notifications/{id}/read
    public function markRead(Request $request, $id)
    {
        $notif = CustomerNotification::where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $notif) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $notif->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }

    // PATCH /customer/notifications/read-all
    public function markAllRead(Request $request)
    {
        CustomerNotification::where('user_id', $request->user()->user_id)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }

    private function notificationPetNames(CustomerNotification $notification): array
    {
        if ($notification->type === CustomerNotification::TYPE_BOOKING_CANCELLED && $notification->pet) return [$notification->pet->pet_name];
        if (str_starts_with($notification->type, 'clinic_reminder_')) {
            return array_filter([$notification->clinicAppointment?->pet?->pet_name]);
        }
        if (
            $notification->type
            === CustomerNotification::TYPE_PET_INFORMATION_UPDATED
        ) {
            return collect([$notification->pet?->pet_name])
                ->filter()
                ->values()
                ->all();
        }

        $petNames = ($notification->booking?->bookingPets ?? collect())
            ->filter(fn ($pet) => ! in_array($notification->type, ['ready_for_pickup', 'pickup_reminder', 'picked_up'], true)
                || $pet->grooming_state !== \App\Models\BookingPet::GROOMING_STATE_CANCELLED)
            ->map(fn ($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! in_array($notification->type, ['grooming_started', 'grooming_finished'], true)) {
            return $petNames;
        }

        $matchedPetNames = $this->petNamesMentionedInMessage($notification->message, $petNames);

        return $matchedPetNames ?: $petNames;
    }

    private function notificationPetTypes(CustomerNotification $notification, array $petNames): array
    {
        if ($notification->type === CustomerNotification::TYPE_BOOKING_CANCELLED && $notification->pet) return [strtolower((string) $notification->pet->species)];
        if (str_starts_with($notification->type, 'clinic_reminder_')) {
            return array_filter([strtolower((string) $notification->clinicAppointment?->pet?->species)]);
        }
        if (
            $notification->type
            === CustomerNotification::TYPE_PET_INFORMATION_UPDATED
        ) {
            return collect([$notification->pet?->species])
                ->map(fn ($type) => mb_strtolower(trim((string) $type)))
                ->filter(fn ($type) => in_array($type, ['dog', 'cat'], true))
                ->values()
                ->all();
        }

        $matchedNames = array_flip(array_map(
            fn ($name) => mb_strtolower(trim((string) $name)),
            $petNames,
        ));

        return ($notification->booking?->bookingPets ?? collect())
            ->filter(function ($bookingPet) use ($matchedNames) {
                if (empty($matchedNames)) {
                    return true;
                }

                $name = mb_strtolower(trim((string) $bookingPet->pet?->pet_name));

                return $name !== '' && isset($matchedNames[$name]);
            })
            ->map(fn ($bookingPet) => mb_strtolower(trim((string) $bookingPet->pet?->species)))
            ->filter(fn ($type) => in_array($type, ['dog', 'cat'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function formatCustomerNotificationMessage(CustomerNotification $notification, array $petNames): string
    {
        if (empty($petNames)) {
            return match ($notification->type) {
                'grooming_started' => "Great news! Your pet has Started Grooming. We'll let you know as soon as they're ready for pickup!",
                'grooming_finished' => "Your pet is Finished with grooming. We'll keep you updated on the rest of the visit.",
                'ready_for_pickup' => 'Your pet is now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.',
                default => $this->formatNotificationMessage($notification->message),
            };
        }

        $subject = $this->formatNameList($petNames);
        $isPlural = count($petNames) > 1;
        $timeLabel = $notification->booking?->preferredArrivalWindow()?->window_label;

        return match ($notification->type) {
            'grooming_started' => "Great news! {$subject} ".($isPlural ? 'have' : 'has')." Started Grooming. We'll let you know as soon as they're ready for pickup!",
            'grooming_finished' => "{$subject} ".($isPlural ? 'are' : 'is')." Finished with grooming. We'll keep you updated on the rest of the visit.",
            'ready_for_pickup' => 'Your '.($isPlural ? 'pets are' : 'pet is').' now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.',
            'pickup_reminder' => "Reminder: {$subject} ".($isPlural ? 'are' : 'is').' still waiting to be picked up at the clinic. Please come at your earliest convenience!',
            'picked_up' => "{$subject} ".($isPlural ? 'have' : 'has').' been released. Thank you for visiting Bethlehem Animal Clinic!',
            'reminder_24h' => $timeLabel
                ? "Reminder: {$subject}'s planned grooming drop-off is tomorrow around {$timeLabel}. Your pet joins the queue after check-in."
                : $this->formatNotificationMessage($notification->message),
            'reminder_3h' => $timeLabel
                ? "Heads up! {$subject}'s planned grooming drop-off is in about 3 hours around {$timeLabel}. Your pet joins the queue after check-in."
                : $this->formatNotificationMessage($notification->message),
            default => $this->formatNotificationMessage($notification->message),
        };
    }

    private function petNamesMentionedInMessage(?string $message, array $petNames): array
    {
        $text = (string) $message;

        return array_values(array_filter($petNames, function ($petName) use ($text) {
            $name = trim((string) $petName);

            if ($name === '') {
                return false;
            }

            return preg_match('/(^|[^\pL\pN])'.preg_quote($name, '/').'($|[^\pL\pN])/iu', $text) === 1;
        }));
    }

    private function formatNotificationMessage(?string $message): string
    {
        $text = (string) $message;

        $text = preg_replace(
            '/\bNew booking\s+(BAC-[A-Za-z0-9-]+)/i',
            'New Online Registration $1',
            $text
        );

        $text = preg_replace(
            '/\bBooking\s+(BAC-[A-Za-z0-9-]+)/i',
            'Online Registration $1',
            $text
        );

        return str_ireplace(['pre-registration', 'grooming appointment'], ['Online Registration', 'grooming visit'], $text);
    }

    private function formatNameList(array $names): string
    {
        $cleanNames = array_values(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $names,
        )));

        if (count($cleanNames) <= 1) {
            return $cleanNames[0] ?? 'your pet';
        }

        if (count($cleanNames) === 2) {
            return $cleanNames[0].' and '.$cleanNames[1];
        }

        return implode(', ', array_slice($cleanNames, 0, -1)).', and '.end($cleanNames);
    }

    private function petOverviewDestination(?int $petId): ?string
    {
        if (! $petId) {
            return null;
        }

        return './pet-details.html?'.http_build_query([
            'pet_id' => $petId,
            'tab' => 'overview',
        ]);
    }

}
