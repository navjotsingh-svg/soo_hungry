<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\Module;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\InboundMessage;
use App\Models\Merchant;
use App\Models\NotificationPermission;
use App\Models\PickupOrder;
use App\Models\ProposedAction;
use App\Models\StaffHandoff;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Domain\Billing\BillingService;
use App\Domain\Cafe\CafeCartBuilder;
use App\Domain\Shared\Alerts;
use App\Domain\Tiffin\RenewalService;
use App\Domain\Tiffin\TiffinPlanner;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class InboundProcessor
{
    public function __construct(
        private readonly PilotPhraseInterpreter $interpreter,
        private readonly IntentRouter $router,
        private readonly TiffinPlanner $tiffin,
        private readonly CafeCartBuilder $carts,
        private readonly ProposalStore $proposals,
        private readonly ActionConfirmer $confirmer,
        private readonly NotificationQueue $notifications,
        private readonly BillingService $billing,
        private readonly RenewalService $renewals,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function receiveWebhook(array $payload, int $eventId): void
    {
        $handled = false;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneNumberId = (string) data_get($change, 'value.metadata.phone_number_id');
                $account = WhatsAppAccount::query()->where('phone_number_id', $phoneNumberId)->first();
                if ($account === null) {
                    WebhookEvent::query()->whereKey($eventId)->update(['resolution' => 'unknown_number']);
                    Log::warning('whatsapp.unknown_number', ['phone_number_id' => $phoneNumberId]);
                    continue;
                }
                foreach (data_get($change, 'value.messages', []) as $message) {
                    $handled = true;
                    $this->accept(
                        $account,
                        (string) ($message['from'] ?? ''),
                        (string) ($message['id'] ?? ''),
                        CarbonImmutable::createFromTimestampUTC((int) ($message['timestamp'] ?? time())),
                        (string) ($message['type'] ?? 'unknown'),
                        $message['text']['body'] ?? null,
                        $message,
                        $eventId,
                    );
                }
            }
        }

        if ($handled) {
            WebhookEvent::query()->whereKey($eventId)->update(['processed_at' => now(), 'resolution' => 'accepted']);
        }
    }

    public function simulate(Merchant $merchant, string $phone, string $body, CarbonImmutable $sentAtLocal): ?InboundMessage
    {
        $account = $merchant->whatsappAccount;
        if ($account === null) {
            return null;
        }

        return $this->accept(
            $account,
            $phone,
            'sim-'.Str::uuid(),
            $sentAtLocal->timezone($merchant->timezone)->utc(),
            'text',
            $body,
            ['source' => 'pilot_console'],
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function accept(
        WhatsAppAccount $account,
        string $from,
        string $providerMessageId,
        CarbonImmutable $sentAtUtc,
        string $type,
        ?string $body,
        array $raw,
        ?int $eventId,
    ): ?InboundMessage {
        if ($providerMessageId === '' || InboundMessage::query()->where('provider_message_id', $providerMessageId)->exists()) {
            return InboundMessage::query()->where('provider_message_id', $providerMessageId)->first();
        }

        $phone = PhoneNumber::normalize($from);
        if ($phone === null) {
            return null;
        }

        $customer = Customer::firstOrCreate(
            ['merchant_id' => $account->merchant_id, 'phone_e164' => $phone],
            ['language' => 'en'],
        );
        $conversation = $customer->conversation()->firstOrCreate(
            ['merchant_id' => $account->merchant_id, 'customer_id' => $customer->id],
            ['whatsapp_account_id' => $account->id, 'status' => 'automated'],
        );
        $conversation->update(['last_customer_message_at' => $sentAtUtc]);

        try {
            $inbound = InboundMessage::create([
                'merchant_id' => $account->merchant_id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'webhook_event_id' => $eventId,
                'provider_message_id' => $providerMessageId,
                'wa_timestamp' => $sentAtUtc,
                'message_type' => $type,
                'body' => $body,
                'raw' => $raw,
                'processing_status' => 'received',
            ]);
        } catch (QueryException) {
            return InboundMessage::query()->where('provider_message_id', $providerMessageId)->first();
        }

        Log::info('whatsapp.inbound', [
            'merchant_id' => $account->merchant_id,
            'from' => PhoneNumber::mask($phone),
            'provider_message_id' => $providerMessageId,
        ]);

        try {
            $this->handle($inbound);
            $inbound->update(['processing_status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $exception) {
            $inbound->update(['processing_status' => 'failed', 'result' => 'failed']);
            Log::error('whatsapp.process_failed', [
                'inbound_id' => $inbound->id,
                'from' => PhoneNumber::mask($phone),
            ]);
            report($exception);
        }

        return $inbound->fresh();
    }

    public function handle(InboundMessage $inbound): void
    {
        if ($inbound->processing_status === 'processed') {
            return;
        }

        $inbound->load('conversation.customer', 'merchant.modules', 'merchant.cutoff', 'merchant.cloverConnection.items');
        $conversation = $inbound->conversation;
        $merchant = $inbound->merchant;
        $customer = $conversation->customer;

        if (! $conversation->automated()) {
            $inbound->update(['result' => 'suppressed_takeover']);

            return;
        }

        if ($inbound->message_type !== 'text' || trim((string) $inbound->body) === '') {
            $this->openHandoff($inbound, 'unsupported_media', 'Unsupported '.$inbound->message_type.' message.');
            $this->reply($inbound, 'Send that as a text message and a person will look at it.');
            $inbound->update(['result' => 'handoff']);

            return;
        }

        $catalog = $this->catalog($merchant);
        $interpretation = $this->interpreter->interpret((string) $inbound->body, $catalog);
        $inbound->update(['draft' => $interpretation->kind.($interpretation->ambiguousPunjabi ? ' punjabi' : '')]);
        $localAt = CarbonImmutable::parse($inbound->wa_timestamp)->timezone($merchant->timezone);

        if ($interpretation->kind === 'opt_out') {
            NotificationPermission::updateOrCreate(
                ['customer_id' => $customer->id],
                ['merchant_id' => $merchant->id, 'proactive_allowed' => false, 'opted_out_at' => now(), 'source' => 'whatsapp'],
            );
            $this->reply($inbound, 'You will not receive reminders. You can still message this chat.');
            $inbound->update(['result' => 'opt_out']);

            return;
        }

        if (in_array($interpretation->kind, ['confirmation', 'selection'], true)) {
            $this->reply($inbound, $this->confirmer->confirmFromCustomer($customer, $interpretation, $localAt, $inbound));
            $inbound->update(['result' => 'confirmation']);

            return;
        }

        if ($interpretation->kind === 'payment_claim') {
            $bill = $customer->bills()->where('status', '!=', 'paid')->latest('id')->first();
            $this->billing->claim($customer, $bill, $conversation);
            $this->reply($inbound, 'Thanks. The kitchen will verify that payment. The balance does not change until they record it.');
            $inbound->update(['result' => 'payment_claim']);

            return;
        }

        if ($interpretation->kind === 'renewal') {
            $subscription = $this->subscription($customer);
            if ($subscription === null || ! $merchant->hasModule(Module::Tiffin)) {
                $this->openHandoff($inbound, 'unclear', 'Renewal request without a tiffin plan.');
                $this->reply($inbound, 'I cannot find a tiffin plan to renew. A person will check.');
            } else {
                $this->renewals->requestFromCustomer($subscription, $localAt);
                $this->reply($inbound, 'Renewal requested. The owner confirms the next cycle. Payment is recorded separately.');
            }
            $inbound->update(['result' => 'renewal']);

            return;
        }

        if ($interpretation->kind === 'handoff') {
            if ($interpretation->handoffReason === 'cancel') {
                $this->reply($inbound, $this->cancel($customer, $inbound));
                $inbound->update(['result' => 'cancel']);

                return;
            }
            $this->openHandoff($inbound, (string) $interpretation->handoffReason, (string) $inbound->body);
            $reply = match ($interpretation->handoffReason) {
                'allergy' => 'I am checking that with the café before anything is ordered.',
                'human' => 'A person will reply in this chat.',
                'feedback' => 'Thanks. A person has your message.',
                default => 'A person will look at this message.',
            };
            $this->reply($inbound, $reply);
            $inbound->update(['result' => 'handoff']);

            return;
        }

        $pendingPickup = ProposedAction::query()
            ->where('customer_id', $customer->id)
            ->where('module', Module::Cafe)
            ->where('status', ActionStatus::Pending)
            ->latest('id')
            ->first();
        if ($pendingPickup !== null && $interpretation->kind !== 'pickup' && $interpretation->kind !== 'tiffin_change') {
            $revised = $this->revisePickup($pendingPickup, (string) $inbound->body, $merchant, $customer, $localAt, $inbound);
            if ($revised !== null) {
                $this->reply($inbound, $revised);
                $inbound->update(['result' => 'revised']);

                return;
            }
        }

        $decision = $this->router->route($merchant->enabledModules(), $interpretation);
        if ($decision->path === 'clarify') {
            $this->reply($inbound, (string) $decision->question);
            $inbound->update(['result' => 'clarify']);

            return;
        }
        if ($decision->path === 'menu') {
            $names = collect($catalog)->pluck('name')->implode(', ');
            $this->reply($inbound, $names === '' ? 'The café menu is not loaded yet.' : 'Which item should I add? '.$names.'.');
            $inbound->update(['result' => 'menu']);

            return;
        }
        if ($decision->path === 'handoff') {
            $this->openHandoff($inbound, (string) ($decision->handoffReason ?? 'unclear'), (string) ($decision->question ?? $inbound->body));
            $this->reply($inbound, $decision->question ?: 'A person will look at this message.');
            $inbound->update(['result' => 'handoff']);

            return;
        }
        if ($decision->path === 'tiffin') {
            $this->proposeTiffin($inbound, $customer, $interpretation, $localAt);

            return;
        }
        if ($decision->path === 'pickup') {
            $this->proposePickup($inbound, $merchant, $customer, $interpretation, $localAt);
        }
    }

    private function proposeTiffin(InboundMessage $inbound, Customer $customer, Interpretation $interpretation, CarbonImmutable $localAt): void
    {
        $subscription = $this->subscription($customer);
        if ($subscription === null) {
            $this->openHandoff($inbound, 'unclear', 'Meal change with no subscription.');
            $this->reply($inbound, 'There is no tiffin plan on this number yet. A person will check. I have not started one.');
            $inbound->update(['result' => 'no_subscription']);

            return;
        }

        $subscription->load('merchant.cutoff', 'scheduleEntries');
        $plan = $this->tiffin->plan($subscription, $interpretation, $localAt);
        if ($plan->question !== null) {
            $this->reply($inbound, $plan->question);
            $inbound->update(['result' => 'clarify']);

            return;
        }

        $action = $this->proposals->open(
            $inbound,
            Module::Tiffin,
            ActionType::TiffinChange,
            [
                'identified_at' => $localAt->toIso8601String(),
                'apply' => $plan->apply,
                'late' => $plan->late,
                'notes' => $plan->notes,
                'open_ended' => $plan->openEnded,
                'resume' => $plan->resume,
                'subscription_id' => $subscription->id,
            ],
            $plan->summary,
            $plan->expiresAt ? CarbonImmutable::parse($plan->expiresAt) : null,
            $plan->staffOnly(),
        );

        if ($plan->late !== []) {
            $this->openHandoff($inbound, 'late_change', $plan->summary, $action);
        }
        $this->reply($inbound, $plan->summary, $action->id);
        $inbound->update(['result' => $plan->staffOnly() ? 'late' : 'proposed']);
    }

    private function proposePickup(InboundMessage $inbound, Merchant $merchant, Customer $customer, Interpretation $interpretation, CarbonImmutable $localAt): void
    {
        $connection = $merchant->cloverConnection;
        $location = $merchant->locations()->first();
        if ($connection === null || $location === null || $connection->ordering_paused || $location->ordering_paused) {
            $this->reply($inbound, 'Pickup ordering is paused. Nothing was sent to the café.');
            $inbound->update(['result' => 'paused']);

            return;
        }
        if (! $connection->catalogIsFresh()) {
            $this->alerts->raise($merchant->id, 'clover', 'Menu sync is stale. A pickup cart was not accepted.', $connection);
            $this->reply($inbound, 'The café menu needs a fresh sync before I can take an order.');
            $inbound->update(['result' => 'stale_menu']);

            return;
        }

        $openOrder = PickupOrder::query()
            ->where('customer_id', $customer->id)
            ->where('submission_status', 'submitted')
            ->whereNotIn('pickup_status', ['collected', 'cancelled'])
            ->exists();
        if ($openOrder) {
            $this->openHandoff($inbound, 'reconciliation', 'Change after a pickup order was submitted.');
            $this->reply($inbound, 'The café already has an order. Staff will reconcile this change. I did not create another order.');
            $inbound->update(['result' => 'reconcile']);

            return;
        }

        $cart = $this->carts->build($merchant, $customer, $interpretation, $localAt);
        $action = $this->proposals->open(
            $inbound,
            Module::Cafe,
            ActionType::Pickup,
            $cart['payload'],
            $cart['summary'],
            null,
        );
        $this->reply($inbound, $cart['summary'], $action->id);
        $inbound->update(['result' => $cart['ready'] ? 'quoted' : 'collecting']);
    }

    private function revisePickup(ProposedAction $action, string $body, Merchant $merchant, Customer $customer, CarbonImmutable $localAt, InboundMessage $inbound): ?string
    {
        $text = mb_strtolower(trim($body));
        $payload = $action->payload;
        $lines = $payload['lines'] ?? [];
        $changed = false;

        if (preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $text, $match)) {
            $hour = (int) $match[1];
            $minute = ($match[2] ?? '') !== '' ? (int) $match[2] : 0;
            if ($match[3] === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($match[3] === 'am' && $hour === 12) {
                $hour = 0;
            }
            $payload['pickup_time'] = sprintf('%02d:%02d', $hour, $minute);
            $payload['pickup_tomorrow'] = str_contains($text, 'tomorrow') || ($payload['pickup_tomorrow'] ?? false);
            $changed = true;
        }

        foreach ($lines as $index => $line) {
            foreach ($line['groups'] ?? [] as $group) {
                foreach ($group['options'] ?? [] as $option) {
                    $name = mb_strtolower((string) $option['name']);
                    $position = strpos($text, $name);
                    if ($position === false) {
                        continue;
                    }
                    $before = substr($text, max(0, $position - 18), min(18, $position));
                    if (str_contains($before, 'second')) {
                        $targets = [1];
                    } elseif (str_contains($before, 'both')) {
                        $targets = array_keys($lines);
                    } else {
                        $targets = [];
                        foreach ($lines as $candidate => $candidateLine) {
                            $already = collect($candidateLine['modifiers'] ?? [])->contains(fn (array $modifier): bool => $modifier['group_id'] === $group['id']);
                            if (! $already && $this->lineHasGroup($candidateLine, (string) $group['id'])) {
                                $targets = [$candidate];
                                break;
                            }
                        }
                    }
                    foreach ($targets as $target) {
                        if (! isset($lines[$target]) || ! $this->lineHasGroup($lines[$target], $group['id'])) {
                            continue;
                        }
                        $lines[$target]['modifiers'] = array_values(array_filter(
                            $lines[$target]['modifiers'] ?? [],
                            fn (array $modifier): bool => $modifier['group_id'] !== $group['id'],
                        ));
                        $lines[$target]['modifiers'][] = [
                            'group_id' => $group['id'],
                            'group_name' => $group['name'],
                            'option_id' => $option['id'],
                            'name' => $option['name'],
                            'price_cents' => $option['price_cents'],
                        ];
                        $changed = true;
                    }
                }
            }
        }

        if (! $changed && ($customer->name === null || $customer->name === '') && preg_match("/^[a-z][a-z .'-]{1,40}$/", $text)) {
            $customer->update(['name' => ucwords($text)]);
            $changed = true;
        }

        if (! $changed) {
            return null;
        }

        $interpretation = new Interpretation(
            kind: 'pickup',
            lines: $lines,
            pickupTime: $payload['pickup_time'] ?? null,
            pickupTomorrow: (bool) ($payload['pickup_tomorrow'] ?? false),
        );
        $cart = $this->carts->build($merchant, $customer->fresh(), $interpretation, $localAt);
        $replacement = $this->proposals->open($inbound, Module::Cafe, ActionType::Pickup, $cart['payload'], $cart['summary'], null);

        return $replacement->summary;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineHasGroup(array $line, string $groupId): bool
    {
        foreach ($line['groups'] ?? [] as $group) {
            if ($group['id'] === $groupId) {
                return true;
            }
        }

        return false;
    }

    private function cancel(Customer $customer, InboundMessage $inbound): string
    {
        $submitted = PickupOrder::query()
            ->where('customer_id', $customer->id)
            ->where('submission_status', 'submitted')
            ->whereNotIn('pickup_status', ['collected', 'cancelled'])
            ->exists();
        if ($submitted) {
            $this->openHandoff($inbound, 'reconciliation', 'Cancel after submission.');

            return 'The café already has this order. Staff will reconcile the cancellation. I did not create or delete an order.';
        }

        ProposedAction::query()
            ->where('customer_id', $customer->id)
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Superseded]);

        return 'I cancelled the order that had not been sent. Nothing was submitted to the café.';
    }

    private function subscription(Customer $customer): ?Subscription
    {
        return Subscription::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Paused])
            ->latest('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(Merchant $merchant): array
    {
        $connection = $merchant->cloverConnection;
        if ($connection === null) {
            return [];
        }

        return $connection->items->map(fn ($item): array => [
            'id' => $item->id,
            'clover_item_id' => $item->clover_item_id,
            'name' => $item->name,
            'price_cents' => $item->price_cents,
            'tax_rate_bps' => $item->tax_rate_bps,
            'available' => $item->available,
            'modifier_groups' => $item->modifier_groups ?? [],
        ])->all();
    }

    private function reply(InboundMessage $inbound, string $body, ?int $proposalId = null): void
    {
        $this->notifications->deliver(
            merchant: $inbound->merchant,
            customer: $inbound->conversation->customer,
            conversation: $inbound->conversation,
            body: $body,
            proactive: false,
            template: null,
            idempotencyKey: 'reply-'.$inbound->id,
            proposalId: $proposalId,
        );
    }

    private function openHandoff(InboundMessage $inbound, string $reason, string $draft, ?ProposedAction $action = null): void
    {
        StaffHandoff::create([
            'merchant_id' => $inbound->merchant_id,
            'conversation_id' => $inbound->conversation_id,
            'inbound_message_id' => $inbound->id,
            'proposed_action_id' => $action?->id,
            'reason' => $reason,
            'draft_interpretation' => $draft,
            'status' => 'open',
        ]);
    }
}
