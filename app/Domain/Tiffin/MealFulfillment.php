<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Enums\PlanKind;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Domain\Shared\Auditor;
use Illuminate\Support\Facades\DB;

final class MealFulfillment
{
    public function __construct(private readonly Auditor $auditor) {}

    public function fulfill(ScheduleEntry $entry, User $actor): ScheduleEntry
    {
        return DB::transaction(function () use ($entry, $actor): ScheduleEntry {
            $locked = ScheduleEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->status === 'fulfilled' || $locked->status !== 'scheduled') {
                return $locked;
            }

            $subscription = Subscription::query()->lockForUpdate()->findOrFail($locked->subscription_id);
            $locked->update([
                'status' => 'fulfilled',
                'fulfilled_at' => now(),
                'fulfillment_key' => 'entry-'.$locked->id,
            ]);

            if ($subscription->plan_kind === PlanKind::PrepaidPack) {
                $subscription->update([
                    'portion_credits_remaining' => max(0, $subscription->portion_credits_remaining - $locked->quantity),
                ]);
            }

            $this->auditor->record($locked->merchant_id, $actor, 'meal.fulfilled', $locked, 'staff', [
                'quantity' => $locked->quantity,
                'credits' => $subscription->fresh()->portion_credits_remaining,
            ]);

            return $locked->fresh();
        });
    }
}
