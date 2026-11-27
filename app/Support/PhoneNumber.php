<?php

declare(strict_types=1);

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    public static function display(?string $e164): string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return sprintf('(%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return $e164 ?: '';
    }

    public static function mask(?string $e164): string
    {
        if ($e164 === null || strlen($e164) < 4) {
            return '•••';
        }

        return '•••'.substr($e164, -4);
    }

    public static function waMe(?string $e164): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164) ?? '';

        return $digits === '' ? null : 'https://wa.me/'.$digits;
    }
}
