<?php

namespace App\Support;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountEmail
{
    public static function normalize(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        return $at === false ? $email : substr($email, 0, $at + 1).Str::lower(substr($email, $at + 1));
    }

    public static function rules(bool $required = true, ?int $maxLength = null): array
    {
        return [new \App\Rules\ValidAccountEmail($required, $maxLength)];
    }

    public static function entryError(mixed $value, bool $required = true, ?int $maxLength = null): ?string
    {
        if ($value === null || $value === '') return $required ? 'Enter your email address.' : null;
        if (! is_string($value)) return 'Enter a valid email address.';
        $email = self::normalize($value);
        if ($email === '') return $required ? 'Enter your email address.' : null;
        if (substr_count($email, '@') !== 1 || preg_match('/\s/', $email)) return 'Enter a valid email address.';
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') return 'Enter a valid email address.';
        // Only ASCII mailbox syntax is supported by the current mail transport.
        if (preg_match('/[^\x21-\x7e]/', $email)) return 'Remove unsupported characters.';
        $policy = config('account_email');
        $maxLength ??= $policy['max_length'];
        if (strlen($local) < $policy['min_local']) return 'Use at least 3 characters before @.';
        if (strlen($local) > $policy['max_local']) return 'Use no more than 64 characters before @.';
        if (strlen($email) > $maxLength) return "Use an email address of {$maxLength} characters or fewer.";
        $atom = "[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~\\-]+";
        if (! preg_match('/^'.$atom.'(?:\\.'.$atom.')*$/D', $local)
            || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/D', $domain)
            || \Illuminate\Support\Facades\Validator::make(['email' => $email], ['email' => 'email:rfc'])->fails()) {
            return 'Enter a valid email address.';
        }
        $domains = array_map('strtolower', array_merge($policy['domains'], $policy['exception_domains']));
        return in_array($domain, $domains, true) ? null : 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.';
    }

    public static function lookupColumn(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'email COLLATE NOCASE' : 'email';
    }

    public static function assertAvailable(string $email, ?User $except = null, bool $lock = false, ?PendingCustomerRegistration $exceptPending = null): void
    {
        $column = self::lookupColumn();
        $pending = PendingCustomerRegistration::query()->unexpired()->whereRaw($column.' = ?', [$email])
            ->when($exceptPending, fn ($query) => $query->whereKeyNot($exceptPending->getKey()));
        $users = User::query()->whereRaw($column.' = ?', [$email])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()));
        if ($lock) {
            $pending->lockForUpdate();
            $users->lockForUpdate();
        }
        if ($pending->first(['id']) || $users->first(['user_id'])) {
            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        }
    }
}
