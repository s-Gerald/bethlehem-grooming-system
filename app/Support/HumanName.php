<?php

namespace App\Support;

final class HumanName
{
    public static function normalize(mixed $value): string
    {
        $name = preg_replace('/\p{Zs}+/u', ' ', (string) $value);
        $name = trim($name ?? '', ' ');
        if ($name !== mb_strtolower($name, 'UTF-8') && $name !== mb_strtoupper($name, 'UTF-8')) {
            return $name;
        }
        $mcNames = config('human_names.mc_names');

        return preg_replace_callback('/(?:(?!\x{02BC})[\p{L}\p{M}])+/u', function ($match) use ($mcNames) {
            $word = mb_strtolower($match[0], 'UTF-8');
            if (in_array($word, $mcNames, true)) {
                return 'Mc'.mb_strtoupper(mb_substr($word, 2, 1, 'UTF-8'), 'UTF-8').mb_substr($word, 3, null, 'UTF-8');
            }

            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($word, 1, null, 'UTF-8');
        }, $name);
    }

    public static function rules(string $type = 'first_name', bool $required = true, ?string $unchanged = null): array
    {
        return [new \App\Rules\ValidHumanName($type, $required, $unchanged)];
    }

    public static function error(mixed $value, string $type = 'first_name', bool $required = true): ?string
    {
        $label = $type === 'last_name' ? 'last name' : 'first name';
        $limit = config('human_names.'.$type.'_max_length');
        if ($value === null || (is_string($value) && trim(preg_replace('/\p{Zs}+/u', ' ', $value) ?? '') === '')) return $required ? 'Enter your '.$label.'.' : null;
        if (! is_string($value)) return 'Enter a valid '.$label.'.';
        if (preg_match('/\p{N}/u', $value)) return "Numbers aren't allowed.";
        if (preg_match('/[^\p{L}\p{M}\p{Zs}\x{0027}\x{2019}\x{02BC}\x{002D}\x{2010}\x{2011}]/u', $value)
            || ! preg_match('/\p{L}/u', str_replace('ʼ', '', $value))) return 'Enter a valid '.$label.'.';
        if (mb_strlen(self::normalize($value), 'UTF-8') > $limit) return ucfirst($label).' must be '.$limit.' characters or fewer.';

        return null;
    }
}
