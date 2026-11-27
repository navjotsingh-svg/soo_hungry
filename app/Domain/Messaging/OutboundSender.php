<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\NotificationPermission;
use App\Models\OutboundNotification;
use App\Models\PaymentReminder;
use App\Models\WhatsAppAccount;
use Throwable;

final class OutboundSender
{
    public function __construct(private readonly WhatsAppClientResolver $clients) {}

    public function send(OutboundNotification $notification): void
    {
        $notification = $notification->fresh();
        if ($notification === null || $notification->status === 'sent') {
            return;
        }

        if ($notification->is_proactive) {
            $permission = NotificationPermission::query()
                ->where('merchant_id', $notification->merchant_id)
                ->where('customer_id', $notification->customer_id)
                ->first();
            if ($permission === null || ! $permission->allowsProactive()) {
                $notification->update([
                    'status' => 'blocked',
                    'failure_reason' => 'Proactive messages are not allowed.',
                    'attempts' => $notification->attempts + 1,
                ]);

                return;
            }
        }

        if ($notification->kind === 'payment_reminder') {
            $reminder = PaymentReminder::query()->where('outbound_notification_id', $notification->id)->first();
            if ($reminder !== null && ($reminder->status === 'cancelled' || $reminder->bill->balanceCents() <= 0)) {
                $reminder->update(['status' => 'cancelled']);
                $notification->update([
                    'status' => 'blocked',
                    'failure_reason' => 'The bill was settled before the reminder was sent.',
                    'attempts' => $notification->attempts + 1,
                ]);

                return;
            }
        }

        $lastMessage = $notification->conversation?->last_customer_message_at;
        $insideWindow = $lastMessage !== null && $lastMessage->greaterThan(now()->subDay());
        if (! $insideWindow && $notification->template_name === null) {
            $notification->update([
                'status' => 'failed',
                'failure_reason' => 'An approved template is required outside the 24-hour window.',
                'attempts' => $notification->attempts + 1,
            ]);

            return;
        }

        try {
            $account = WhatsAppAccount::query()->where('merchant_id', $notification->merchant_id)->first();
            $client = $this->clients->forAccount($account);
            $to = (string) $notification->customer?->phone_e164;
            $providerId = $notification->template_name
                ? $client->sendTemplate($account, $to, $notification->template_name, $notification->body)
                : $client->sendText($account, $to, $notification->body);
            $notification->update([
                'status' => 'sent',
                'provider_message_id' => $providerId,
                'sent_at' => now(),
                'attempts' => $notification->attempts + 1,
                'failure_reason' => null,
            ]);
            if ($notification->kind === 'payment_reminder') {
                PaymentReminder::query()->where('outbound_notification_id', $notification->id)->update(['status' => 'sent']);
            }
        } catch (Throwable $exception) {
            $notification->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'attempts' => $notification->attempts + 1,
            ]);
            if ($notification->kind === 'payment_reminder') {
                PaymentReminder::query()->where('outbound_notification_id', $notification->id)->update(['status' => 'failed']);
            }
        }
    }
}
