<?php

namespace Tests\Feature;

use App\Rules\AccountPassword;
use App\Support\AccountUsername;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountPasswordPolicyTest extends TestCase
{
    public static function passwords(): array
    {
        return [
            ['customer', 'orchard', 'Use at least 8 characters.'],
            ['customer', 'orchards', null],
            ['customer', 'meadow'.str_repeat('x', 58), null],
            ['customer', 'meadow'.str_repeat('x', 59), 'Use no more than 64 characters.'],
            ['admin', 'violet orchard', 'Use at least 15 characters.'],
            ['staff', 'violet orchards!', null],
            ['admin', 'meadow'.str_repeat('x', 58), null],
            ['staff', 'meadow'.str_repeat('x', 59), 'Use no more than 64 characters.'],
            ['staff', 'violet meadow river', null],
            ['customer', 'VIOLETORCHARDS', null],
            ['customer', '7492603851', null],
            ['customer', '[]{}\\\'"!@#$%^&*()-_=+;:,<>/?`~.|', null],
            ['customer', 'two  internal spaces', null],
            ['customer', ' orchard lantern', 'Remove spaces at the beginning or end.'],
            ['customer', 'orchard lantern ', 'Remove spaces at the beginning or end.'],
            ['customer', 'orchard café', 'Use English letters, numbers, or standard symbols only.'],
            ['customer', "orchard\tlantern", 'Use English letters, numbers, or standard symbols only.'],
            ['customer', "orchard\0lantern", 'Use English letters, numbers, or standard symbols only.'],
            ['customer', 'aaaaaaaa', 'Choose a less predictable password.'],
            ['staff', 'passwordpassword', 'Choose a less predictable password.'],
            ['customer', '12345678', 'Choose a less predictable password.'],
        ];
    }

    #[DataProvider('passwords')]
    public function test_new_password_acceptance(string $role, string $password, ?string $error): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('A', 35).':0')]);
        $validator = Validator::make(['password' => $password], ['password' => AccountPassword::rules($role)], AccountPassword::messages());
        $this->assertSame($error === null, $validator->passes());
        if ($error) $this->assertSame($error, $validator->errors()->first('password'));
        Http::assertSentCount($error === null ? 1 : 0);
    }

    public static function usernames(): array
    {
        return [['ab', false], ['abc', true], [str_repeat('a', 30), true], [str_repeat('a', 31), false],
            ['1abc', false], ['abc.', false], ['a._-b9', true], ['café', false], ['abc def', false]];
    }

    public function test_breached_password_is_rejected_for_every_role_using_only_the_hash_prefix(): void
    {
        $password = 'violet orchard meadow';
        $digest = strtoupper(sha1($password));
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($digest, 5).':42')]);
        foreach (['customer', 'admin', 'staff'] as $role) {
            $validator = Validator::make(['password' => $password], ['password' => AccountPassword::rules($role)]);
            $this->assertFalse($validator->passes());
            $this->assertSame('This password is not safe. Choose another.', $validator->errors()->first('password'));
        }
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($digest, 0, 5)
            && $request->hasHeader('Add-Padding', 'true') && $request->body() === '');
    }

    #[DataProvider('usernames')]
    public function test_username_format(string $username, bool $valid): void
    {
        $this->assertSame($valid, Validator::make(['username' => $username], ['username' => AccountUsername::rules()])->passes());
    }
}
