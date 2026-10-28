<?php

namespace Tests\Unit;

use App\Support\AccountEmail;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    public function test_stale_cached_configuration_uses_the_shared_email_policy(): void
    {
        config()->set('account_email', null);
        $this->assertNull(AccountEmail::entryError('customer@gmail.com'));
        $this->assertSame('Use at least 3 characters before @.', AccountEmail::entryError('ab@gmail.com'));
        $this->assertSame('Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.', AccountEmail::entryError('customer@unknown.example'));
    }

    #[DataProvider('entryCases')]
    public function test_entry_policy(string $email, bool $valid, ?string $message = null): void
    {
        $validator = Validator::make(['email' => $email], ['email' => AccountEmail::rules()]);
        $this->assertSame($valid, $validator->passes(), $email);
        if ($message) $this->assertSame($message, $validator->errors()->first('email'));
    }

    public static function entryCases(): array
    {
        $cases = [];
        foreach (['gmail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.com.ph', 'ymail.com', 'icloud.com', 'me.com', 'mac.com'] as $domain) {
            $cases[$domain] = ['123@'.$domain, true];
        }
        return $cases + [
            'case' => ['Gerald+Clinic@GMAIL.COM', true],
            'alias' => ["o'brien+clinic@gmail.com", true],
            '64 bytes' => [str_repeat('a', 64).'@gmail.com', true],
            '65 bytes' => [str_repeat('a', 65).'@gmail.com', false, 'Use no more than 64 characters before @.'],
            'short' => ['ab@gmail.com', false, 'Use at least 3 characters before @.'],
            'empty' => ['', false, 'Enter your email address.'],
            'unknown' => ['123@123.com', false, 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.'],
            'typo' => ['abc@gmial.com', false],
            'subdomain' => ['abc@mail.gmail.com', false],
            'missing at' => ['abc.gmail.com', false],
            'double at' => ['abc@@gmail.com', false],
            'dots' => ['a..bc@gmail.com', false],
            'leading dot' => ['.abc@gmail.com', false],
            'trailing dot' => ['abc.@gmail.com', false],
            'space' => ['ab c@gmail.com', false],
            'unicode' => ['gérald@gmail.com', false, 'Remove unsupported characters.'],
            'quoted' => ['"abc"@gmail.com', false],
            'bad domain' => ['abc@-gmail.com', false],
        ];
    }

    public function test_normalization_preserves_the_local_part(): void
    {
        $this->assertSame('Gerald+Clinic@gmail.com', AccountEmail::normalize('  Gerald+Clinic@GMAIL.COM  '));
    }

    public function test_optional_email_and_explicit_operational_exception(): void
    {
        $this->assertTrue(Validator::make(['email' => null], ['email' => AccountEmail::rules(required: false)])->passes());
        config()->set('account_email.exception_domains', ['clinic.example']);
        $this->assertTrue(Validator::make(['email' => 'frontdesk@CLINIC.EXAMPLE'], ['email' => AccountEmail::rules()])->passes());
        $this->assertFalse(Validator::make(['email' => 'frontdesk@other.example'], ['email' => AccountEmail::rules()])->passes());
    }
    public function test_both_walkin_requests_validate_new_email_and_preserve_selected_legacy_contacts(): void
    {
        foreach ([\App\Http\Requests\StoreWalkinRequest::class, \App\Http\Requests\StoreClinicWalkinRequest::class] as $class) {
            $request = $class::create('/', 'POST', ['owner_record_type' => 'new']);
            $rule = $request->rules()['email'];
            $this->assertFalse(Validator::make(['email' => '123@123.com'], ['email' => $rule])->passes());
            $this->assertTrue(Validator::make(['email' => null], ['email' => $rule])->passes());
            $this->assertTrue(Validator::make(['email' => str_repeat('a', 64).'@gmail.com'], ['email' => $rule])->passes());
            $request->merge(['owner_record_type' => 'registered']);
            $this->assertTrue(Validator::make(['email' => 'ab@legacy.example'], ['email' => $request->rules()['email']])->passes());
        }
    }

    public function test_complete_email_length_matches_the_target_column(): void
    {
        $domain = str_repeat('b', 50).'.'.str_repeat('c', 50).'.example';
        config()->set('account_email.exception_domains', [$domain]);
        $email = str_repeat('a', 64).'@'.$domain;
        $this->assertFalse(Validator::make(['email' => $email], ['email' => AccountEmail::rules()])->passes());
        $validator = Validator::make(['email' => $email], ['email' => AccountEmail::rules(maxLength: 255)]);
        $this->assertTrue($validator->passes(), $validator->errors()->first('email'));
    }

    public function test_default_complete_length_comes_from_the_shared_policy(): void
    {
        config()->set('account_email.max_length', 15);
        $validator = Validator::make(['email' => 'customer+clinic@gmail.com'], ['email' => AccountEmail::rules()]);
        $this->assertFalse($validator->passes());
        $this->assertSame('Use an email address of 15 characters or fewer.', $validator->errors()->first('email'));
    }

}

