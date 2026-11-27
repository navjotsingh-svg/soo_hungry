<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Enums\SubscriptionStatus;
use App\Models\ProposedAction;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Domain\Shared\Auditor;
use Illuminate\Support\Facades\DB;

final class TiffinApplier
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  list<array<string, mixed>>  $meals
     */
    public function apply(ProposedAction $action, array $meals, ?int $authorizedBy, string $source): void
    {
        $subscription = Subscription::query()
            ->where('customer_id', $action->customer_id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Paused])
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return;
        }

        DB::transaction(function () use ($action, $meals, $authorizedBy, $source, $subscription): void {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $openPause = false;

            foreach ($meals as $meal) {
                if (($meal['effect'] ?? '') === 'open_pause') {
                    $openPause = true;
                    continue;
                }
                if (($meal['effect'] ?? '') === 'resume') {
                    $subscription->update([
                        'status' => SubscriptionStatus::Active,
                        'pause_open_ended' => false,
                        'paused_until' => null,
                    ]);
                    ScheduleEntry::query()
                        ->where('subscription_id', $subscription->id)
                        ->whereDate('service_date', '>=', $meal['date'])
                        ->where('status', 'paused')
                        ->update([
                            'status' => 'scheduled',
                            'credit_treatment' => null,
                            'proposed_action_id' => $action->id,
                        ]);
                    continue;
                }
                if (! isset($meal['date'], $meal['slot'])) {
                    continue;
                }

                $entry = ScheduleEntry::query()
                    ->where('subscription_id', $subscription->id)
                    ->whereDate('service_date', $meal['date'])
                    ->where('slot', $meal['slot'])
                    ->lockForUpdate()
                    ->first();

                if ($entry === null || in_array($entry->status, ['skipped', 'paused', 'fulfilled', 'cancelled'], true)) {
                    continue;
                }

                $quantity = min((int) $meal['quantity'], (int) $entry->quantity);
                if ($quantity < 1) {
                    continue;
                }

                if ($quantity >= (int) $entry->quantity) {
                    $entry->update([
                        'status' => ($meal['effect'] ?? '') === 'pause' ? 'paused' : 'skipped',
                        'skipped_quantity' => $entry->planned_quantity,
                        'quantity' => 0,
                        'credit_treatment' => 'preserve',
                        'proposed_action_id' => $action->id,
                        'authorized_late_by' => $authorizedBy,
                    ]);
                } else {
                    $entry->update([
                        'quantity' => $entry->quantity - $quantity,
                        'skipped_quantity' => $entry->skipped_quantity + $quantity,
                        'proposed_action_id' => $action->id,
                        'authorized_late_by' => $authorizedBy,
                    ]);
                }
            }

            if ($openPause) {
                $subscription->update([
                    'status' => SubscriptionStatus::Paused,
                    'pause_open_ended' => true,
                ]);
            }

            $this->auditor->record($action->merchant_id, null, 'tiffin.applied', $action, $source, [
                'meals' => $meals,
                'authorized_late_by' => $authorizedBy,
                'credits' => $subscription->fresh()->portion_credits_remaining,
            ]);
        });
    }
}
