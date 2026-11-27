<?php

declare(strict_types=1);

namespace App\Enums;

enum Module: string
{
    case Cafe = 'cafe';
    case Tiffin = 'tiffin';

    public function label(): string
    {
        return match ($this) {
            self::Cafe => 'Café pickup',
            self::Tiffin => 'Tiffin',
        };
    }
}
