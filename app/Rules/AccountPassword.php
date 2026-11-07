<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Throwable;

class AccountPassword implements ValidationRule
{
    public function __construct(private string $role = 'customer', private array $context = [], private bool $screen = true) {}

    public static function minimum(string $role): int
    {
        return $role === 'customer' ? 8 : 15;
    }

    public static function rules(string $role, array $context = [], string $presence = 'required', bool $screen = true): array
    {
        return ['bail', $presence, 'string', new self($role, $context, $screen)];
    }

    public static function messages(): array
    {
        return ['password.required' => 'Enter a new password.',
            'password_confirmation.required' => 'Confirm your new password.',
            'password_confirmation.required_with' => 'Confirm your new password.',
            'password_confirmation.same' => 'Passwords do not match.'];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            $fail('Use English letters, numbers, or standard symbols only.');
            return;
        }
        if (str_starts_with($value, ' ') || str_ends_with($value, ' ')) {
            $fail('Remove spaces at the beginning or end.');
            return;
        }
        if (strlen($value) < self::minimum($this->role)) {
            $fail('Use at least '.self::minimum($this->role).' characters.');
            return;
        }
        if (strlen($value) > 64) {
            $fail('Use no more than 64 characters.');
            return;
        }
        if (! $this->screen) return;

        $candidate = strtolower($value);
        $details = array_filter($this->context, 'is_string');
        $email = $details['email'] ?? '';
        $context = ['bethlehem', 'bethlehem veterinary clinic', 'bethlehem animal clinic',
            'password', 'qwerty', 'letmein', '12345678', '123456789', '1234567890', $details['username'] ?? '', $details['new_username'] ?? '',
            $email, explode('@', $email)[0],
            trim(($details['first_name'] ?? '').' '.($details['last_name'] ?? ''))];
        foreach (array_filter($context) as $word) {
            $word = mb_strtolower($word);
            if ($candidate === $word || preg_match('/\A(?:'.preg_quote($word, '/').'){2,}[\d\W_]*\z/u', $candidate)
                || preg_match('/\A'.preg_quote($word, '/').'[\d\W_]*\z/u', $candidate)) {
                $fail('Choose a less predictable password.');
                return;
            }
        }
        if (preg_match('/\A(.{1,4})\1+\z/s', $candidate)
            || str_contains('abcdefghijklmnopqrstuvwxyz', $candidate)
            || str_contains('98765432109876543210', $candidate)) {
            $fail('Choose a less predictable password.');
            return;
        }

        // SHA-1 is only the HIBP screening identifier, never the stored password hash.
        // Only a five-character prefix leaves the server; do not log lookup failures.
        $digest = strtoupper(sha1($value));
        try {
            $response = Http::connectTimeout(2)->timeout(5)->withHeaders(['Add-Padding' => 'true'])
                ->get('https://api.pwnedpasswords.com/range/'.substr($digest, 0, 5));
            if (! $response->successful()) throw new \RuntimeException;
            $validRows = 0;
            foreach (preg_split('/\r\n|\r|\n/', $response->body()) as $line) {
                if (! preg_match('/\A([A-F0-9]{35}):(\d+)\z/i', trim($line), $parts)) continue;
                $validRows++;
                if (hash_equals(substr($digest, 5), strtoupper($parts[1])) && (int) $parts[2] > 0) {
                    $fail('This password is not safe. Choose another.');
                    return;
                }
            }
            if (! $validRows) throw new \RuntimeException;
        } catch (Throwable) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'password_screening_unavailable',
                'message' => 'Password safety checking is temporarily unavailable. Please try again later.'], 503)
                ->header('Cache-Control', 'no-store')->header('Retry-After', '60'));
        }
    }
}
