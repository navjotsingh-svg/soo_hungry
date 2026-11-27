<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Models\ScheduleEntry;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

final class ScheduleGenerator
{
    public function generate(Subscription $subscription, CarbonImmutable $through): int
    {
        $days = array_map('intval', $subscription->service_days);
        $zone = $subscription->merchant->timezone;
        $cursor = CarbonImmutable::parse($subscription->start_date->toDateString(), $zone)->startOfDay();
        $end = $through->timezone($zone)->startOfDay();
        $created = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            if (in_array($cursor->dayOfWeekIso, $days, true)) {
                foreach (['breakfast' => (int) $subscription->breakfast_portions, 'lunch' => (int) $subscription->lunch_portions, 'dinner' => (int) $subscription->dinner_portions] as $slot => $quantity) {
                    if ($quantity < 1 || ! $subscription->serves($cursor->dayOfWeekIso, $slot)) {
                        continue;
                    }
                    $existing = ScheduleEntry::query()
                        ->where('subscription_id', $subscription->id)
                        ->where('slot', $slot)
                        ->whereDate('service_date', $cursor->toDateString())
                        ->exists();
                    if ($existing) {
                        continue;
                    }

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
                    $created++;
                }
            }
            $cursor = $cursor->addDay();
        }

        return $created;
    }
}
