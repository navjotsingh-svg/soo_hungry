<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    public static function cad(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign.'$'.number_format(abs($cents) / 100, 2);
    }
}
