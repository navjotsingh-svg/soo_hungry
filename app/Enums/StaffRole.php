<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffRole: string
{
    case Owner = 'owner';
    case Service = 'service';
    case Kitchen = 'kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Service => 'Service',
            self::Kitchen => 'Kitchen',
        };
    }

    public function seesFinancials(): bool
    {
        return $this === self::Owner;
    }

    public function changesCredentials(): bool
    {
        return $this === self::Owner;
    }
}
