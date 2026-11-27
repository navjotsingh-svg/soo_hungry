<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\OutboundNotification;
use Illuminate\Support\Facades\DB;

final class NotificationQueue
{
    public function queue(
        Merchant $merchant,
        ?Customer $customer,
        ?Conversation $conversation,
        string $body,
        bool $proactive,
        ?string $template,
        string $idempotencyKey,
        ?int $proposalId = null,
        string $kind = 'service_reply',
        string $source = 'automation',
    ): OutboundNotification {
        $notification = OutboundNotification::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'merchant_id' => $merchant->id,
                'customer_id' => $customer?->id,
                'conversation_id' => $conversation?->id,
                'proposed_action_id' => $proposalId,
                'kind' => $kind,
                'template_name' => $template,
                'body' => $body,
                'is_proactive' => $proactive,
                'source' => $source,
                'status' => 'queued',
            ],
        );

        return $notification;
    }

    public function deliver(
        Merchant $merchant,
        ?Customer $customer,
        ?Conversation $conversation,
        string $body,
        bool $proactive,
        ?string $template,
        string $idempotencyKey,
        ?int $proposalId = null,
        string $kind = 'service_reply',
        string $source = 'automation',
    ): OutboundNotification {
        $notification = $this->queue(
            $merchant,
            $customer,
            $conversation,
            $body,
            $proactive,
            $template,
            $idempotencyKey,
            $proposalId,
            $kind,
            $source,
        );
        $this->push($notification);

        return $notification;
    }

    public function push(OutboundNotification $notification): void
    {
        if ($notification->status !== 'queued') {
            return;
        }

        DB::afterCommit(function () use ($notification): void {
            \App\Jobs\SendOutboundNotification::dispatch($notification->id);
        });
    }
}
