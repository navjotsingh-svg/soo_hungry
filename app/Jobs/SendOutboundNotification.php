<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Messaging\OutboundSender;
use App\Models\OutboundNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOutboundNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $notificationId) {}

    public function handle(OutboundSender $sender): void
    {
        $notification = OutboundNotification::query()->find($this->notificationId);
        if ($notification !== null) {
            $sender->send($notification);
        }
    }
}
