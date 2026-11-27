<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Superseded = 'superseded';
    case StaffReview = 'staff_review';
    case Expired = 'expired';
}
