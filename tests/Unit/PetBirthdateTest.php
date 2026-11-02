<?php

namespace Tests\Unit;

use App\Rules\ValidPetBirthdate;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PetBirthdateTest extends TestCase
{
    public function test_range_uses_manila_midnight_and_real_calendar_dates(): void
    {
        foreach ([
            ['2026-11-02 15:59:59 UTC', '2026-11-02', '1986-11-02', '1986-11-01', '2026-11-03'],
            ['2026-11-02 16:00:00 UTC', '2026-11-03', '1986-11-03', '1986-11-02', '2026-11-04'],
            ['2028-02-28 16:00:00 UTC', '2028-02-29', '1988-02-29', '1988-02-28', '2028-03-01'],
            ['2100-02-28 16:00:00 UTC', '2100-03-01', '2060-03-01', '2060-02-29', '2100-03-02'],
            ['2120-02-28 16:00:00 UTC', '2120-02-29', '2080-02-29', '2080-02-28', '2120-03-01'],
        ] as [$now, $today, $min, $old, $future]) {
            $this->travelTo(\Carbon\Carbon::parse($now));
            foreach ([$today, $min, null, ''] as $valid) {
                $this->assertTrue(Validator::make(['birthdate' => $valid], ['birthdate' => ['nullable', new ValidPetBirthdate]])->passes());
            }
            foreach ([$old => 'Enter a birthdate within the last 40 years.', $future => 'Birthdate cannot be in the future.',
                '2026-02-30' => 'Enter a valid birthdate.', '2026-2-03' => 'Enter a valid birthdate.',
                '2026-11-03T00:00:00Z' => 'Enter a valid birthdate.'] as $invalid => $message) {
                $this->assertSame($message, Validator::make(['birthdate' => $invalid], ['birthdate' => ['nullable', new ValidPetBirthdate]])->errors()->first('birthdate'));
            }
        }
    }

    public function test_leap_day_is_clamped_when_the_lower_year_is_not_a_leap_year(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2140-02-29 10:00:00', 'Asia/Manila'));
        $this->assertTrue(Validator::make(['birthdate' => '2100-02-28'], ['birthdate' => [new ValidPetBirthdate]])->passes());
        $this->assertFalse(Validator::make(['birthdate' => '2100-02-27'], ['birthdate' => [new ValidPetBirthdate]])->passes());
    }
}
