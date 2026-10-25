<?php

namespace Tests\Unit;

use App\Services\ClinicSmsMessage;
use PHPUnit\Framework\TestCase;

class ClinicSmsMessageTest extends TestCase
{
    public function test_otp_has_one_header_its_own_footer_and_one_segment(): void
    {
        $text = str_replace(':otp', '482913', ClinicSmsMessage::otp());
        $this->assertSame("Bethlehem Animal Clinic\nYour verification code is 482913. Valid for 5 minutes.\nDo not share.", $text);
        $this->assertSame(92, ClinicSmsMessage::units($text));
    }

    public function test_single_pet_is_dynamic_and_multiple_pets_are_not_concatenated(): void
    {
        $this->assertSame("Bethlehem Animal Clinic\nJoseph's grooming is complete.\nPlease visit the clinic for pickup.", ClinicSmsMessage::grooming(['Joseph']));
        $this->assertSame("Bethlehem Animal Clinic\nYour pets' grooming is complete.\nPlease visit the clinic for pickup.", ClinicSmsMessage::grooming(['Joseph', 'Helio']));
    }

    public function test_unsafe_names_fall_back_and_extension_units_are_counted(): void
    {
        foreach ([null, '', '   ', str_repeat('A', 110), "Pet\nInjected", "Pet\tName", '🐕', '小白', 'Bethlehem Animal Clinic'] as $name) {
            $text = ClinicSmsMessage::grooming([$name]);
            $this->assertSame("Bethlehem Animal Clinic\nYour pet's grooming is complete.\nPlease visit the clinic for pickup.", $text);
            $this->assertSame(92, ClinicSmsMessage::units($text));
        }
        $this->assertSame(20, ClinicSmsMessage::units('^{}\\[~]|€' . "\f"));
        $this->assertNull(ClinicSmsMessage::units('’'));
        $this->assertSame(160, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('A', 76)])));
        $this->assertSame(92, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('^', 39)])));
    }
}
