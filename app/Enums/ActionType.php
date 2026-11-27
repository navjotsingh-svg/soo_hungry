<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionType: string
{
    case Pickup = 'pickup';
    case TiffinChange = 'tiffin_change';
    case Renewal = 'renewal';
}
