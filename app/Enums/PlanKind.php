<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanKind: string
{
    case PrepaidPack = 'prepaid_pack';
    case FixedPeriod = 'fixed_period';
}
