<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\PlanKind;
use App\Enums\SubscriptionStatus;
use App\Models\Bill;
use App\Models\Merchant;
use App\Models\PaymentReminder;
use App\Models\Subscription;
use App\Domain\Messaging\NotificationQueue;
use App\Support\Money;
use Carbon\CarbonImmutable;

final class PaymentReminderService
{
    public function __construct(private readonly NotificationQueue $queue) {}

    public function dispatchDue(CarbonImmutable $nowUtc): int
    {
        $queued = 0;

        foreach (Merchant::query()->get() as $merchant) {
            $local = $nowUtc->timezone($merchant->timezone);
            if ($local->format('H:i') !== substr((string) $merchant->reminder_local_time, 0, 5)) {
                continue;
            }

            foreach (Bill::query()->where('merchant_id', $merchant->id)->whereDate('due_date', $local->toDateString())->get() as $bill) {
                if ($bill->balanceCents() <= 0) {
                    continue;
                }
                $subscription = $bill->subscription;
                if ($subscription !== null && ($subscription->pause_open_ended || $subscription->status === SubscriptionStatus::Paused)) {
                    continue;
                }
                if (! $bill->customer->permission?->allowsProactive()) {
                    continue;
                }

                $dueOn = $bill->due_date->toDateString();
                $reminder = PaymentReminder::query()
                    ->where('bill_id', $bill->id)
                    ->whereDate('due_on', $dueOn)
                    ->first();
                if ($reminder === null) {
                    $reminder = PaymentReminder::create([
                        'bill_id' => $bill->id,
                        'due_on' => $dueOn,
                        'merchant_id' => $merchant->id,
                        'customer_id' => $bill->customer_id,
                        'status' => 'pending',
                    ]);
                }
                if ($reminder->outbound_notification_id !== null || in_array($reminder->status, ['sent', 'cancelled'], true)) {
                    continue;
                }

                $notification = $this->queue->queue(
                    merchant: $merchant,
                    customer: $bill->customer,
                    conversation: $bill->customer->conversation,
                    body: $this->body($merchant, $bill),
                    proactive: true,
                    template: 'payment_reminder',
                    idempotencyKey: 'reminder-'.$reminder->id,
                    kind: 'payment_reminder',
                );
                $reminder->update(['outbound_notification_id' => $notification->id]);
                $this->queue->push($notification);
                $queued++;
            }
        }

        return $queued;
    }

    private function body(Merchant $merchant, Bill $bill): string
    {
        $period = $bill->period_start->format('M j').' – '.$bill->period_end->format('M j');

        return $merchant->name.' bill for '.$period.'. Amount due '.Money::cad($bill->balanceCents()).'. '.$merchant->payment_instructions;
    }
}
