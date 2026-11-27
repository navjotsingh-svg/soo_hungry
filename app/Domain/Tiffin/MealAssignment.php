<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Domain\Shared\Auditor;
use App\Enums\PlanKind;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MealAssignment
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  list<int>  $days
     */
    public function assign(Customer $customer, array $days, int $breakfast, int $lunch, int $dinner, ?string $diet, User $actor, ?array $dayMeals = null): Subscription
    {
        $days = $dayMeals === null
            ? array_values(array_unique(array_map('intval', $days)))
            : array_map('intval', array_keys($dayMeals));
        sort($days);

        return DB::transaction(function () use ($customer, $days, $breakfast, $lunch, $dinner, $diet, $actor, $dayMeals): Subscription {
            $subscription = Subscription::query()
                ->where('merchant_id', $customer->merchant_id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Paused])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($subscription === null) {
                $zone = $customer->merchant->timezone;
                $start = CarbonImmutable::now($zone)->startOfDay();
                $subscription = Subscription::create([
                    'merchant_id' => $customer->merchant_id,
                    'customer_id' => $customer->id,
                    'status' => SubscriptionStatus::Active,
                    'plan_kind' => PlanKind::FixedPeriod,
                    'service_days' => $days,
                    'day_meals' => $dayMeals,
                    'breakfast_portions' => $breakfast,
                    'lunch_portions' => $lunch,
                    'dinner_portions' => $dinner,
                    'meal_type' => 'regular',
                    'diet' => $diet,
                    'plan_price_cents' => 0,
                    'pack_size' => 0,
                    'pack_sequence' => 1,
                    'start_date' => $start->toDateString(),
                    'period_end' => $start->addDays((int) config('pilot.schedule_horizon_days'))->toDateString(),
                    'portion_credits_remaining' => 0,
                    'address' => $customer->address,
                    'terms_summary' => 'Days and meals set by staff. This does not create a bill.',
                    'customer_confirmed_at' => now(),
                    'owner_approved_at' => now(),
                    'owner_approved_by' => $actor->id,
                ]);
            } else {
                $subscription->update([
                    'service_days' => $days,
                    'day_meals' => $dayMeals,
                    'breakfast_portions' => $breakfast,
                    'lunch_portions' => $lunch,
                    'dinner_portions' => $dinner,
                    'diet' => $diet,
                ]);
            }

            $this->sync($subscription->fresh());
            $this->auditor->record($customer->merchant_id, $actor, 'tiffin.assigned', $subscription, 'staff', [
                'days' => $days,
                'breakfast' => $breakfast,
                'lunch' => $lunch,
                'dinner' => $dinner,
                'diet' => $diet,
                'day_meals' => $dayMeals,
            ]);

            return $subscription->fresh();
        });
    }

    public function skip(ScheduleEntry $entry, User $actor): ScheduleEntry
    {
        return DB::transaction(function () use ($entry, $actor): ScheduleEntry {
            $locked = ScheduleEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'scheduled') {
                return $locked;
            }

            $locked->update([
                'status' => 'skipped',
                'skipped_quantity' => $locked->planned_quantity,
                'quantity' => 0,
                'credit_treatment' => 'preserve',
            ]);
            $this->auditor->record($locked->merchant_id, $actor, 'meal.skipped', $locked, 'staff', [
                'date' => $locked->service_date->toDateString(),
                'slot' => $locked->slot,
            ]);

            return $locked->fresh();
        });
    }

    private function sync(Subscription $subscription): void
    {
        $zone = $subscription->merchant->timezone;
        $cursor = CarbonImmutable::now($zone)->startOfDay();
        $end = $cursor->addDays((int) config('pilot.schedule_horizon_days'));
        while ($cursor->lessThanOrEqualTo($end)) {
            foreach ([
                'breakfast' => (int) $subscription->breakfast_portions,
                'lunch' => (int) $subscription->lunch_portions,
                'dinner' => (int) $subscription->dinner_portions,
            ] as $slot => $quantity) {
                $entry = ScheduleEntry::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('slot', $slot)
                    ->whereDate('service_date', $cursor->toDateString())
                    ->first();
                $wanted = $quantity > 0 && $subscription->serves($cursor->dayOfWeekIso, $slot);

                if (! $wanted) {
                    if ($entry !== null && $entry->status === 'scheduled') {
                        $entry->delete();
                    }
                    continue;
                }

                if ($entry === null) {
                    ScheduleEntry::create([
                        'subscription_id' => $subscription->id,
                        'service_date' => $cursor->toDateString(),
                        'slot' => $slot,
                        'merchant_id' => $subscription->merchant_id,
                        'customer_id' => $subscription->customer_id,
                        'planned_quantity' => $quantity,
                        'quantity' => $quantity,
                        'meal_type' => $subscription->meal_type,
                        'diet' => $subscription->diet,
                        'status' => 'scheduled',
                    ]);
                    continue;
                }

                if ($entry->status === 'scheduled' && ((int) $entry->quantity !== $quantity || $entry->diet !== $subscription->diet)) {
                    $entry->update([
                        'planned_quantity' => $quantity,
                        'quantity' => $quantity,
                        'diet' => $subscription->diet,
                    ]);
                }
            }

            $cursor = $cursor->addDay();
        }
    }
}
