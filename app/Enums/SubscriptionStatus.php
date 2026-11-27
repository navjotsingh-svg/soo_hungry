<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
