<?php

namespace App\Services;

class ClinicSmsMessage
{
    private const HEADER = 'Bethlehem Animal Clinic';
    private const PICKUP = 'Please visit the clinic for pickup.';

    public static function otp(): string
    {
        return self::HEADER."\nYour verification code is :otp. Valid for 5 minutes.\nDo not share.";
    }

    /** @param array<int, ?string> $petNames */
    public static function grooming(array $petNames): string
    {
        $body = count($petNames) > 1 ? "Your pets' grooming is complete." : "Your pet's grooming is complete.";
        $name = trim((string) ($petNames[0] ?? ''));
        if (count($petNames) === 1 && $name !== '' && ! preg_match('/[\x00-\x1f\x7f]/', $name)
            && ! str_contains($name, self::HEADER)) {
            $candidate = self::HEADER."\n{$name}'s grooming is complete.\n".self::PICKUP;
            $units = self::units($candidate);
            if ($units !== null && $units <= 160) return $candidate;
        }

        return self::HEADER."\n{$body}\n".self::PICKUP;
    }

    public static function units(string $message): ?int
    {
        $basic = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $extension = "\f^{}\\[~]|€";
        $units = 0;
        foreach (mb_str_split($message) as $character) {
            if (str_contains($basic, $character)) $units++;
            elseif (str_contains($extension, $character)) $units += 2;
            else return null;
        }

        return $units;
    }
}
