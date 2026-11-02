<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CustomerPassword implements ValidationRule
{
    public function __construct(private User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Preserve the deployed bcrypt format and verify every byte, including spaces.
        $limit = min(72, (int) (config('hashing.bcrypt.limit') ?: 72));
        if (strlen($value) > $limit || str_contains($value, "\0")) {
            $fail('Use at most '.$limit.' bytes, without null characters. Unicode characters can use more than one byte.');
            return;
        }

        $candidate = mb_strtolower(trim($value));
        $context = ['bethlehem', 'bethlehem veterinary clinic', 'bethlehem animal clinic',
            'password', 'qwerty', 'letmein', '1234567890', $this->user->username,
            $this->user->email, explode('@', $this->user->email)[0],
            trim($this->user->first_name.' '.$this->user->last_name)];
        foreach (array_filter($context) as $word) {
            $word = mb_strtolower($word);
            if ($candidate === $word || preg_match('/\A(?:'.preg_quote($word, '/').'){2,}[\d\W_]*\z/u', $candidate)
                || preg_match('/\A'.preg_quote($word, '/').'[\d\W_]*\z/u', $candidate)) {
                $fail('Choose a less predictable password that is not commonly used or based on your account or clinic name.');
                return;
            }
        }
        if ($candidate === '' || preg_match('/\A(.)\1+\z/us', $candidate)) {
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
                    $fail('This password has appeared in a data breach. Choose a different password.');
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
