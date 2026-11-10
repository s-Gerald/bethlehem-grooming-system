<?php

namespace Tests\Unit;

use App\Services\ClinicSmsMessage;
use PHPUnit\Framework\TestCase;

class ClinicSmsMessageTest extends TestCase
{
    public function test_otp_has_no_clinic_header_and_keeps_its_security_footer_in_one_segment(): void
    {
        $text = str_replace(':otp', '482913', ClinicSmsMessage::otp());
        $this->assertSame("Your verification code is 482913. Valid for 5 minutes.\nDo not share.", $text);
        $this->assertStringNotContainsString('Bethlehem Animal Clinic', ClinicSmsMessage::otp());
        $this->assertSame(68, ClinicSmsMessage::units($text));
    }

    public function test_single_pet_is_dynamic_and_multiple_pets_are_not_concatenated(): void
    {
        $this->assertSame("Joseph's grooming is complete.\nPlease visit the clinic for pickup.", ClinicSmsMessage::grooming(['Joseph']));
        $this->assertSame("Your pets' grooming is complete.\nPlease visit the clinic for pickup.", ClinicSmsMessage::grooming(['Joseph', 'Helio']));
        $this->assertSame(66, ClinicSmsMessage::units(ClinicSmsMessage::grooming(['Joseph'])));
        $this->assertSame(68, ClinicSmsMessage::units(ClinicSmsMessage::grooming(['Joseph', 'Helio'])));
        foreach ([[], ['Joseph'], ['Joseph', 'Helio'], [null]] as $names) {
            $this->assertStringNotContainsString('Bethlehem Animal Clinic', ClinicSmsMessage::grooming($names));
        }
    }

    public function test_unsafe_names_fall_back_and_extension_units_are_counted(): void
    {
        foreach ([null, '', '   ', str_repeat('A', 110), "Pet\nInjected", "Pet\tName", '🐕', '小白', 'Bethlehem Animal Clinic'] as $name) {
            $text = ClinicSmsMessage::grooming([$name]);
            $this->assertSame("Your pet's grooming is complete.\nPlease visit the clinic for pickup.", $text);
            $this->assertSame(68, ClinicSmsMessage::units($text));
        }
        $this->assertSame(20, ClinicSmsMessage::units('^{}\\[~]|€' . "\f"));
        $this->assertNull(ClinicSmsMessage::units('’'));
        $this->assertSame(160, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('A', 100)])));
        $this->assertSame(160, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('^', 50)])));
        $this->assertSame(68, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('^', 51)])));
        $this->assertSame(68, ClinicSmsMessage::units(ClinicSmsMessage::grooming([str_repeat('A', 101)])));
    }
}
