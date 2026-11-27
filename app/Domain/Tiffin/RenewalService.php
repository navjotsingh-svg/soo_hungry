<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Enums\PlanKind;
use App\Enums\SubscriptionStatus;
use App\Models\RenewalPrompt;
use App\Models\Subscription;
use App\Models\User;
use App\Domain\Billing\BillingService;
use App\Domain\Messaging\NotificationQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RenewalService
{
    public function __construct(
        private readonly CoveragePlanner $coverage,
        private readonly BillingService $billing,
        private readonly NotificationQueue $queue,
    ) {}

    public function consider(Subscription $subscription, CarbonImmutable $today): ?RenewalPrompt
    {
        if ($subscription->pause_open_ended || $subscription->status === SubscriptionStatus::Paused) {
            return null;
        }
        if (! $this->eligible($subscription, $today)) {
            return null;
        }

        return $this->open($subscription, $today, proactive: true);
    }

    public function requestFromCustomer(Subscription $subscription, CarbonImmutable $today): RenewalPrompt
    {
        return $this->open($subscription, $today, proactive: false);
    }

    public function approve(RenewalPrompt $prompt, User $owner): \App\Models\Bill
    {
        return DB::transaction(function () use ($prompt, $owner) {
            $locked = RenewalPrompt::query()->lockForUpdate()->findOrFail($prompt->id);
            if ($locked->bill_id !== null) {
                return $locked->bill;
            }

            $subscription = Subscription::query()->lockForUpdate()->findOrFail($locked->subscription_id);
            $today = CarbonImmutable::now($subscription->merchant->timezone);
            $due = $today->addDays(5);

            if ($subscription->plan_kind === PlanKind::PrepaidPack) {
                $periodEnd = $today->addDays(30);
                $bill = $this->billing->issue($subscription, 'renewal-'.$locked->id, 'renewal', $today->toDateString(), $today->toDateString(), $periodEnd->toDateString(), $due->toDateString());
                $subscription->update([
                    'portion_credits_remaining' => $subscription->portion_credits_remaining + $subscription->pack_size,
                    'pack_sequence' => $subscription->pack_sequence + 1,
                ]);
            } else {
                $start = CarbonImmutable::parse($subscription->period_end?->toDateString() ?? $today->toDateString())->addDay();
                $end = $start->addDays(max(1, (int) $subscription->cycle_days) - 1);
                $bill = $this->billing->issue($subscription, 'renewal-'.$locked->id, 'renewal', $today->toDateString(), $start->toDateString(), $end->toDateString(), $due->toDateString());
                $subscription->update(['period_end' => $end->toDateString()]);
            }

            $locked->update(['status' => 'approved', 'bill_id' => $bill->id, 'approved_by' => $owner->id]);

            return $bill;
        });
    }

    private function open(Subscription $subscription, CarbonImmutable $today, bool $proactive): RenewalPrompt
    {
        $last = $this->lastCovered($subscription, $today);
        $prompt = RenewalPrompt::firstOrCreate(
            ['subscription_id' => $subscription->id, 'cycle_key' => $this->cycleKey($subscription)],
            [
                'merchant_id' => $subscription->merchant_id,
                'status' => $proactive ? 'pending' : 'requested',
                'last_covered_date' => $last,
            ],
        );

        if ($proactive && $prompt->outbound_notification_id === null) {
            $when = $last ? CarbonImmutable::parse($last)->format('l, M j') : 'no further day';
            $body = $subscription->portion_credits_remaining.' meals left. Last covered day is '.$when.'. Reply renew if you want the next pack.';
            $notification = $this->queue->deliver(
                merchant: $subscription->merchant,
                customer: $subscription->customer,
                conversation: $subscription->customer->conversation,
                body: $body,
                proactive: true,
                template: 'renewal_prompt',
                idempotencyKey: 'renewal-'.$prompt->id,
                kind: 'renewal_prompt',
            );
            $prompt->update(['outbound_notification_id' => $notification->id, 'status' => 'sent']);
        }

        return $prompt->fresh();
    }

    public function lastCovered(Subscription $subscription, CarbonImmutable $today): ?string
    {
        if ($subscription->plan_kind === PlanKind::FixedPeriod && $subscription->period_end !== null) {
            return $subscription->period_end->toDateString();
        }

        $entries = $subscription->scheduleEntries()
            ->orderBy('service_date')
            ->orderBy('slot')
            ->get()
            ->map(fn ($entry): array => [
                'date' => $entry->service_date->toDateString(),
                'quantity' => (int) $entry->quantity,
                'status' => $entry->status,
            ])->all();

        return $this->coverage->lastCovered((int) $subscription->portion_credits_remaining, $entries, $today->toDateString());
    }

    private function eligible(Subscription $subscription, CarbonImmutable $today): bool
    {
        if ($subscription->plan_kind === PlanKind::PrepaidPack) {
            return $subscription->renewal_portion_threshold !== null
                && $subscription->portion_credits_remaining <= $subscription->renewal_portion_threshold;
        }

        if ($subscription->period_end === null || $subscription->renewal_days_threshold === null) {
            return false;
        }

        $days = CarbonImmutable::parse($today->toDateString())->diffInDays(CarbonImmutable::parse($subscription->period_end->toDateString()));

        return (int) round($days) <= (int) $subscription->renewal_days_threshold;
    }

    private function cycleKey(Subscription $subscription): string
    {
        if ($subscription->plan_kind === PlanKind::PrepaidPack) {
            return 'pack-'.$subscription->pack_sequence;
        }

        return 'period-'.($subscription->period_end?->toDateString() ?? 'open');
    }
}
