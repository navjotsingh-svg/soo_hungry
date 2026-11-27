<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\NotificationPermission;
use App\Models\Subscription;
use App\Models\User;
use App\Domain\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SubscriptionEnrollment
{
    public function __construct(
        private readonly ScheduleGenerator $schedules,
        private readonly BillingService $billing,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function propose(Customer $customer, array $attributes): Subscription
    {
        $start = CarbonImmutable::parse((string) $attributes['start_date'], $customer->merchant->timezone);

        return Subscription::create([
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PendingConfirmation,
            'plan_kind' => $attributes['plan_kind'],
            'service_days' => array_map('intval', $attributes['service_days']),
            'lunch_portions' => $attributes['lunch_portions'],
            'dinner_portions' => $attributes['dinner_portions'],
            'meal_type' => $attributes['meal_type'] ?? 'regular',
            'plan_price_cents' => $attributes['plan_price_cents'],
            'pack_size' => $attributes['pack_size'] ?? 0,
            'pack_sequence' => 1,
            'cycle_days' => $attributes['cycle_days'] ?? null,
            'start_date' => $start->toDateString(),
            'period_end' => $attributes['period_end'] ?? null,
            'portion_credits_remaining' => $attributes['opening_credits'] ?? $attributes['pack_size'] ?? 0,
            'renewal_portion_threshold' => $attributes['renewal_portion_threshold'] ?? null,
            'renewal_days_threshold' => $attributes['renewal_days_threshold'] ?? null,
            'address' => $attributes['address'] ?? $customer->address,
            'packing_notes' => $attributes['packing_notes'] ?? null,
            'terms_summary' => $attributes['terms_summary'],
        ]);
    }

    public function customerConfirm(Subscription $subscription): Subscription
    {
        $subscription->update([
            'customer_confirmed_at' => now(),
            'status' => SubscriptionStatus::PendingApproval,
        ]);

        $permission = NotificationPermission::firstOrCreate(
            ['customer_id' => $subscription->customer_id],
            ['merchant_id' => $subscription->merchant_id, 'proactive_allowed' => false, 'source' => 'unknown'],
        );
        if ($permission->opted_out_at === null) {
            $permission->update(['proactive_allowed' => true, 'source' => 'plan_confirmation']);
        }

        return $subscription->fresh();
    }

    public function ownerApprove(
        Subscription $subscription,
        User $owner,
        ?CarbonImmutable $issueDate = null,
        ?CarbonImmutable $dueDate = null,
    ): Subscription {
        return DB::transaction(function () use ($subscription, $owner, $issueDate, $dueDate): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status === SubscriptionStatus::Active) {
                return $locked;
            }
            if ($locked->customer_confirmed_at === null) {
                throw new RuntimeException('The customer has not confirmed the plan terms.');
            }

            $address = $locked->address ?: $locked->customer->address;
            if ($address === null || $address === '') {
                throw new RuntimeException('Approve the address before the plan starts.');
            }

            $zone = $locked->merchant->timezone;
            $start = CarbonImmutable::parse($locked->start_date->toDateString(), $zone);
            $issued = $issueDate?->timezone($zone) ?? CarbonImmutable::now($zone);
            $due = $dueDate?->timezone($zone) ?? $issued->addDays(5);
            $periodEnd = $locked->period_end
                ? CarbonImmutable::parse($locked->period_end->toDateString(), $zone)
                : $start->addDays((int) config('pilot.schedule_horizon_days'));

            $locked->update([
                'status' => SubscriptionStatus::Active,
                'address' => $address,
                'owner_approved_at' => now(),
                'owner_approved_by' => $owner->id,
            ]);
            $locked->customer->update([
                'address' => $address,
                'address_approved_at' => now(),
                'address_approved_by' => $owner->id,
            ]);

            $this->schedules->generate($locked->fresh(), $start->addDays((int) config('pilot.schedule_horizon_days')));
            $this->billing->issue(
                $locked,
                'start',
                'start',
                $issued->toDateString(),
                $start->toDateString(),
                $periodEnd->toDateString(),
                $due->toDateString(),
            );

            return $locked->fresh();
        });
    }
}
