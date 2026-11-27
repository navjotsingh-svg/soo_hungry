<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\Module;
use App\Models\Customer;
use App\Models\InboundMessage;
use App\Models\PickupOrder;
use App\Models\ProposedAction;
use App\Models\StaffHandoff;
use App\Models\User;
use App\Domain\Cafe\CafeCartBuilder;
use App\Domain\Cafe\CafeOrderService;
use App\Domain\Cafe\CloverGateway;
use App\Domain\Cafe\PickupQuoter;
use App\Domain\Tiffin\CutoffClock;
use App\Domain\Tiffin\TiffinApplier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ActionConfirmer
{
    public function __construct(
        private readonly CutoffClock $clock,
        private readonly TiffinApplier $tiffin,
        private readonly CafeCartBuilder $carts,
        private readonly PickupQuoter $quoter,
        private readonly CloverGateway $clover,
        private readonly CafeOrderService $orders,
        private readonly ProposalStore $proposals,
    ) {}

    public function confirmFromCustomer(Customer $customer, Interpretation $interpretation, CarbonImmutable $confirmedAt, InboundMessage $inbound): string
    {
        $pending = ProposedAction::query()
            ->where('customer_id', $customer->id)
            ->where('status', ActionStatus::Pending)
            ->orderBy('id')
            ->get();

        if ($interpretation->kind === 'selection') {
            $action = $pending->get(($interpretation->selection ?? 1) - 1);
            if ($action === null) {
                return 'Reply with the number of the action you want.';
            }

            return $this->confirm($action, $confirmedAt, 'whatsapp');
        }

        if ($pending->count() === 0) {
            return 'There is nothing waiting to confirm.';
        }
        if ($pending->count() > 1) {
            $lines = $pending->values()->map(fn (ProposedAction $action, int $index): string => ($index + 1).'. '.$action->summary)->implode(' ');

            return 'Which action do you want to confirm? '.$lines;
        }

        return $this->confirm($pending->first(), $confirmedAt, 'whatsapp');
    }

    public function confirm(ProposedAction $action, CarbonImmutable $confirmedAt, string $source, ?User $actor = null): string
    {
        if ($action->module === Module::Tiffin) {
            return $this->confirmTiffin($action, $confirmedAt, $source, $actor);
        }

        return $this->confirmPickup($action, $confirmedAt, $source);
    }

    public function approveLate(ProposedAction $action, User $owner): string
    {
        $payload = $action->payload;
        $late = $payload['late'] ?? [];
        if ($late === []) {
            return 'There is no late change waiting on this request.';
        }

        $this->tiffin->apply($action, $late, $owner->id, 'staff');
        $payload['late'] = [];
        $payload['late_applied'] = true;
        $updates = ['payload' => $payload];
        if ($action->status === ActionStatus::StaffReview || ($payload['apply'] ?? []) === []) {
            $updates['status'] = ActionStatus::Confirmed;
            $updates['confirmed_at'] = $action->confirmed_at ?? now();
            $updates['applied_at'] = now();
        }
        $action->update($updates);

        return 'The late change is approved and the packing list now includes it.';
    }

    private function confirmTiffin(ProposedAction $action, CarbonImmutable $confirmedAt, string $source, ?User $actor): string
    {
        return DB::transaction(function () use ($action, $confirmedAt, $source, $actor): string {
            $locked = ProposedAction::query()->lockForUpdate()->findOrFail($action->id);
            if ($locked->status === ActionStatus::Confirmed) {
                return 'Already confirmed. Nothing else was changed.';
            }
            if ($locked->status !== ActionStatus::Pending) {
                return 'That action is not waiting for a customer confirmation.';
            }

            $merchant = $locked->merchant;
            $payload = $locked->payload;
            $identified = CarbonImmutable::parse($payload['identified_at'])->timezone($merchant->timezone);
            $still = [];
            $becameLate = [];
            foreach ($payload['apply'] ?? [] as $meal) {
                if (! isset($meal['date'])) {
                    $still[] = $meal;
                    continue;
                }
                $eligibility = $this->clock->eligibility(
                    $identified,
                    $confirmedAt->timezone($merchant->timezone),
                    CarbonImmutable::parse($meal['date'], $merchant->timezone),
                    $merchant->cutoffTime(),
                    $merchant->graceMinutes(),
                    $actor !== null,
                );
                if ($eligibility === 'late') {
                    $meal['eligibility'] = 'late';
                    $becameLate[] = $meal;
                } else {
                    $still[] = $meal;
                }
            }

            if ($still === [] ) {
                $payload['apply'] = [];
                $payload['late'] = array_merge($payload['late'] ?? [], $becameLate);
                $locked->update(['status' => ActionStatus::StaffReview, 'payload' => $payload]);
                $this->handoff($locked, 'The confirmation was after the grace period.');

                return 'That confirmation is past the cutoff grace period. The owner has to approve it. Nothing was changed.';
            }

            $this->tiffin->apply($locked, $still, $actor?->id, $source);
            if ($becameLate !== []) {
                $payload['late'] = array_merge($payload['late'] ?? [], $becameLate);
                $payload['apply'] = $still;
                $this->handoff($locked, 'Part of the change is now past the cutoff.');
            }
            $locked->update([
                'status' => ActionStatus::Confirmed,
                'payload' => $payload,
                'confirmed_at' => $confirmedAt,
                'applied_at' => now(),
            ]);

            return 'Confirmed. '.$locked->summary;
        });
    }

    private function confirmPickup(ProposedAction $action, CarbonImmutable $confirmedAt, string $source): string
    {
        $locked = ProposedAction::query()->findOrFail($action->id);
        if ($locked->status === ActionStatus::Confirmed) {
            $order = PickupOrder::query()->where('proposed_action_id', $locked->id)->first();
            if ($order !== null) {
                $order = $this->orders->submit($order);
            }

            return $order ? $this->orders->customerMessage($order) : 'Already confirmed. Nothing else was changed.';
        }
        if ($locked->status !== ActionStatus::Pending) {
            return 'That action is not waiting for confirmation.';
        }

        $payload = $locked->payload;
        if (! ($payload['ready'] ?? false)) {
            return $locked->summary;
        }

        $lines = $this->carts->refreshLines($payload['lines'] ?? []);
        foreach ($lines as $line) {
            if (! ($line['available'] ?? false) || ($line['missing_groups'] ?? []) !== []) {
                $locked->update(['payload' => array_merge($payload, ['lines' => $lines, 'ready' => false]), 'summary' => 'An item changed. '.implode(' ', array_map(fn ($row) => implode(', ', $row['missing_groups'] ?? []), $lines))]);

                return 'The menu changed. Tell me a replacement before I send this to the café.';
            }
        }

        $quote = $this->quoter->quote($lines, true, true, false);
        $connection = $locked->merchant->cloverConnection;
        $checkout = $this->clover->checkout($connection, ['lines' => $lines], $quote->totalCents);
        if ($checkout->totalCents !== (int) ($payload['quoted_total_cents'] ?? -1)) {
            $payload['lines'] = $lines;
            $payload['quoted_total_cents'] = $checkout->totalCents;
            $payload['quoted_subtotal_cents'] = $checkout->subtotalCents;
            $payload['quoted_tax_cents'] = $checkout->taxCents;
            $summary = 'The total changed to '.Money::cad($checkout->totalCents).' including tax. Reply YES to confirm the new total.';
            $this->proposals->open($locked->conversation->messages()->find($locked->inbound_message_id) ?? $this->syntheticInbound($locked), Module::Cafe, ActionType::Pickup, $payload, $summary, null);

            return $summary;
        }

        $customer = $locked->customer;
        if (! $this->orders->linkCustomer($customer, $connection)) {
            return 'A person needs to match your café profile before the order is sent. Nothing was submitted.';
        }

        $order = $this->orders->createLocal(
            $locked,
            $customer,
            $lines,
            (string) $customer->name,
            (string) $payload['pickup_at'],
            $quote->subtotalCents,
            $quote->taxCents,
            $checkout->totalCents,
        );
        $locked->update(['status' => ActionStatus::Confirmed, 'confirmed_at' => $confirmedAt, 'applied_at' => now()]);
        $order = $this->orders->submit($order);

        return $this->orders->customerMessage($order);
    }

    private function handoff(ProposedAction $action, string $draft): void
    {
        StaffHandoff::create([
            'merchant_id' => $action->merchant_id,
            'conversation_id' => $action->conversation_id,
            'proposed_action_id' => $action->id,
            'inbound_message_id' => $action->inbound_message_id,
            'reason' => 'late_change',
            'draft_interpretation' => $draft,
            'status' => 'open',
        ]);
    }

    private function syntheticInbound(ProposedAction $action): InboundMessage
    {
        return InboundMessage::query()->findOrFail($action->inbound_message_id);
    }
}
