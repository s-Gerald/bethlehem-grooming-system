<?php

namespace App\Services;

use App\Jobs\SendGroomingCompletionSms;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\User;
use App\Support\PhilippineMobile;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GroomingCompletionSmsService
{
    public function record(Booking $booking): void
    {
        if (! config('services.grooming_completion_sms.enabled', false) || ! $booking->grooming_finished_at) return;
        $recipient = $this->resolveRecipient($booking);
        if (! $recipient) return;
        $inserted = DB::table('grooming_completion_sms')->insertOrIgnore([
            'booking_id' => $booking->getKey(), 'user_id' => $recipient['user_id'],
            'walkin_id' => $recipient['walkin_id'], 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted) DB::afterCommit(fn () => $this->dispatchPending($booking->getKey()));
    }

    public function dispatchPending(?int $bookingId = null): void
    {
        if (! config('services.grooming_completion_sms.enabled', false)) return;
        // A failed queue insertion leaves the durable event for the scheduler.
        try {
            $events = DB::table('grooming_completion_sms')->where('status', 'pending')
                ->when($bookingId !== null, fn ($q) => $q->where('booking_id', $bookingId))
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))
                ->orderBy('id')->limit(100)->pluck('id');
            foreach ($events as $id) {
                DB::transaction(function () use ($id) {
                    $event = DB::table('grooming_completion_sms')->where('id', $id)->lockForUpdate()->first();
                    if (! $event || $event->status !== 'pending' || ($event->queued_at && now()->subMinutes(5)->lt($event->queued_at))) return;
                    if (config('queue.connections.database.driver') !== 'database'
                        || (config('queue.connections.database.connection') ?? DB::getDefaultConnection()) !== DB::getDefaultConnection()) return;
                    Bus::dispatch((new SendGroomingCompletionSms($id))->beforeCommit());
                    DB::table('grooming_completion_sms')->where('id', $id)->update(['queued_at' => now(), 'updated_at' => now()]);
                });
            }
        } catch (\Throwable) {
            // No provider payload or credentials in logs or completion responses.
        }
    }

    public function deliver(int $id): void
    {
        if (! config('services.grooming_completion_sms.enabled', false)) return;
        // Persist uncertain BEFORE the external boundary. A crash or duplicate job
        // cannot submit this event again, even if the acceptance response is lost.
        if (! DB::table('grooming_completion_sms')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'uncertain', 'submitted_at' => now(), 'updated_at' => now()])) return;
        $status = 'uncertain';
        $providerId = null;
        try {
            $event = DB::table('grooming_completion_sms')->where('id', $id)->first();
            $booking = Booking::with('bookingPets.pet')->find($event->booking_id);
            $recipient = $booking?->grooming_finished_at ? $this->resolveRecipient($booking) : null;
            if (! $recipient || $recipient['user_id'] !== $event->user_id || $recipient['walkin_id'] !== $event->walkin_id) {
                $status = 'skipped';
            } elseif (! app()->environment('testing') && (Cache::getStore() instanceof ArrayStore || Cache::getStore() instanceof NullStore)) {
                $status = 'failed';
            } else {
                $activePets = $booking->bookingPets->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED);
                if ($activePets->isEmpty() || ! $activePets->every(fn ($pet) => $pet->grooming_state === BookingPet::GROOMING_STATE_FINISHED && $pet->grooming_end_time)) {
                    $status = 'skipped';
                } else {
                    app(SmsSendBudget::class)->reserve($recipient['user_id'], $recipient['phone']);
                    $result = app(IprogSmsOtpService::class)->sendMessage($recipient['phone'],
                        ClinicSmsMessage::grooming($activePets->map(fn ($pet) => $pet->pet?->pet_name)->values()->all()));
                    $status = $result['status'];
                    $providerId = $result['provider_message_id'];
                }
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
            $status = 'failed';
        } catch (\Throwable) {
            // Retain uncertainty; never blindly retry a potentially accepted send.
        }
        DB::table('grooming_completion_sms')->where('id', $id)->update([
            'status' => $status, 'provider_message_id' => $providerId, 'updated_at' => now(),
        ]);
    }

    /** @return array{user_id: ?int, walkin_id: ?int, phone: string}|null */
    private function resolveRecipient(Booking $booking): ?array
    {
        $walkin = $booking->walkin_id !== null ? $booking->loadMissing('walkin')->walkin : null;
        $bookingUserId = $booking->user_id;
        $walkinUserId = $walkin?->user_id;

        // Conflicting account links leave the recipient ambiguous.
        if ($bookingUserId !== null && $walkinUserId !== null && $bookingUserId !== $walkinUserId) {
            return null;
        }

        if ($bookingUserId !== null || $walkinUserId !== null) {
            $user = $bookingUserId !== null
                ? $booking->loadMissing('user')->user
                : $walkin->loadMissing('user')->user;

            return $this->eligible($user)
                ? ['user_id' => $user->getKey(), 'walkin_id' => null, 'phone' => $user->phone]
                : null;
        }

        if (! $walkin) return null;

        $phone = PhilippineMobile::normalize($walkin->phone);
        return PhilippineMobile::entryError($phone) === null
            ? ['user_id' => null, 'walkin_id' => $walkin->getKey(), 'phone' => $phone]
            : null;
    }

    private function eligible(?User $user): bool
    {
        return $user && $user->role === 'customer' && $user->is_active && ! $user->is_archived
            && ! $user->account_deleted_at && $user->phone_verified_at && preg_match('/^09[0-9]{9}$/D', (string) $user->phone);
    }
}
