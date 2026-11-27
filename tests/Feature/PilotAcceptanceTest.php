<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\CollectionReport;
use App\Domain\Billing\PaymentReminderService;
use App\Domain\Cafe\SimulatedCloverGateway;
use App\Domain\Messaging\ActionConfirmer;
use App\Domain\Messaging\InboundProcessor;
use App\Domain\Tiffin\MealFulfillment;
use App\Domain\Tiffin\RenewalService;
use App\Enums\ActionStatus;
use App\Enums\StaffRole;
use App\Models\Bill;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\InboundMessage;
use App\Models\MerchantUser;
use App\Models\NotificationPermission;
use App\Models\Payment;
use App\Models\PaymentTask;
use App\Models\PickupOrder;
use App\Models\ProposedAction;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class PilotAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cafe_only_lunch_tomorrow_never_creates_a_subscription(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($world['merchant']);

        $this->message($world['account']->phone_number_id, '+16045550199', 'one lunch tomorrow', '2026-11-26 10:00:00');

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, ProposedAction::query()->where('module', 'tiffin')->count());
        $this->assertSame(0, PickupOrder::query()->count());
    }

    public function test_hybrid_messages_confirm_separately_and_only_pickup_reaches_clover(): void
    {
        $world = PilotWorld::merchant('Hybrid', ['cafe', 'tiffin']);
        PilotWorld::catalog($world['merchant']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $gateway = app(SimulatedCloverGateway::class);

        $this->message($world['account']->phone_number_id, '+16045550123', 'one latte, large, oat, pickup tomorrow at 12pm', '2026-11-26 10:00:00');
        $this->message($world['account']->phone_number_id, '+16045550123', 'skip friday lunch', '2026-11-26 10:05:00');
        $this->assertSame(2, ProposedAction::query()->where('status', ActionStatus::Pending)->count());

        $this->message($world['account']->phone_number_id, '+16045550123', '2', '2026-11-26 10:06:00');
        $this->message($world['account']->phone_number_id, '+16045550123', '1', '2026-11-26 10:07:00');

        $this->assertSame(1, PickupOrder::query()->count());
        $this->assertSame(1, $gateway->creates);
        $this->assertSame('skipped', ScheduleEntry::query()->where('subscription_id', $plan->id)->whereDate('service_date', '2026-11-27')->where('slot', 'lunch')->value('status'));
        $this->assertSame(6, $plan->fresh()->portion_credits_remaining);
    }

    public function test_a_second_merchant_cannot_see_the_first_packing_list(): void
    {
        $first = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $second = PilotWorld::merchant('North Kitchen', ['tiffin'], '22:00:00');
        PilotWorld::plan($first['merchant'], $first['user'], '+16045550123');
        PilotWorld::plan($second['merchant'], $second['user'], '+16045550123');

        $this->actingAs($first['user'])->withSession(['merchant_id' => $first['merchant']->id])
            ->get('/packing?date=2026-11-27')
            ->assertOk()
            ->assertSee('Jaspreet Kaur')
            ->assertDontSee('North Kitchen');

        $this->assertSame(1, ScheduleEntry::query()->where('merchant_id', $first['merchant']->id)->whereDate('service_date', '2026-11-27')->where('slot', 'lunch')->count());
    }

    public function test_developer_demo_removes_wednesday_lunch_and_leaves_dinner(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');

        $this->message($world['account']->phone_number_id, '+16045550123', 'Kal lunch na bhejna ji, dinner bhej dena.', '2026-11-24 19:59:00');
        $proposal = ProposedAction::query()->first();
        $this->assertNotNull($proposal);
        $this->assertStringContainsString('Wednesday lunch', $proposal->summary);
        $this->assertStringContainsString('Dinner stays scheduled', $proposal->summary);

        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-24 20:01:00');

        $this->assertSame('skipped', $this->entry($plan, '2026-11-25', 'lunch')->status);
        $this->assertSame('scheduled', $this->entry($plan, '2026-11-25', 'dinner')->status);
        $this->assertSame(6, $plan->fresh()->portion_credits_remaining);

        $this->message($world['account']->phone_number_id, '+16045550999', 'Kal lunch na bhejna ji, dinner bhej dena.', '2026-11-24 20:05:00');
        $this->assertSame(0, ScheduleEntry::query()->where('customer_id', Customer::query()->where('phone_e164', '+16045550999')->value('id'))->where('status', 'skipped')->count());
    }

    public function test_a_late_wednesday_to_friday_pause_offers_only_thursday_and_friday(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');

        $this->message($world['account']->phone_number_id, '+16045550123', 'pause wednesday to friday', '2026-11-24 20:05:00');
        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-24 20:06:00');

        $this->assertSame('scheduled', $this->entry($plan, '2026-11-25', 'lunch')->status);
        $this->assertSame('paused', $this->entry($plan, '2026-11-26', 'lunch')->status);
        $this->assertSame('paused', $this->entry($plan, '2026-11-27', 'lunch')->status);

        $proposal = ProposedAction::query()->first();
        app(ActionConfirmer::class)->approveLate($proposal, $world['user']);
        $wednesday = $this->entry($plan, '2026-11-25', 'lunch');
        $this->assertSame('paused', $wednesday->status);
        $this->assertSame($world['user']->id, $wednesday->authorized_late_by);
    }

    public function test_duplicate_skip_and_fulfillment_do_not_move_dinner_or_credits_twice(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 3);

        $this->message($world['account']->phone_number_id, '+16045550123', 'skip tuesday lunch', '2026-11-23 09:00:00');
        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-23 09:01:00');
        $this->message($world['account']->phone_number_id, '+16045550123', 'skip tuesday lunch', '2026-11-23 09:02:00');
        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-23 09:03:00');

        $fulfillment = app(MealFulfillment::class);
        $monday = $this->entry($plan, '2026-11-23', 'lunch');
        $fulfillment->fulfill($monday, $world['user']);
        $fulfillment->fulfill($monday->fresh(), $world['user']);

        $this->assertSame('scheduled', $this->entry($plan, '2026-11-23', 'dinner')->status);
        $this->assertSame(1, $this->entry($plan, '2026-11-23', 'dinner')->quantity);
        $this->assertSame(2, $plan->fresh()->portion_credits_remaining);
        $this->assertSame(1, ScheduleEntry::query()->where('status', 'fulfilled')->count());
    }

    public function test_two_meals_left_names_thursday_as_the_last_covered_day_once(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', dinner: 0, credits: 3);
        $this->entry($plan, '2026-11-24', 'lunch')->update(['status' => 'skipped', 'quantity' => 0, 'credit_treatment' => 'preserve']);
        app(MealFulfillment::class)->fulfill($this->entry($plan, '2026-11-23', 'lunch'), $world['user']);
        $plan->customer->permission->update([
            'proactive_allowed' => true,
            'opted_out_at' => null,
            'source' => 'plan_confirmation',
        ]);

        $monday = CarbonImmutable::parse('2026-11-23 08:00', 'America/Vancouver');
        $prompt = app(RenewalService::class)->consider($plan->fresh(), $monday);
        $again = app(RenewalService::class)->consider($plan->fresh(), $monday);

        $this->assertNotNull($prompt);
        $this->assertSame('2026-11-26', $prompt->last_covered_date->toDateString());
        $body = \App\Models\OutboundNotification::query()->find($prompt->outbound_notification_id)?->body;
        $this->assertStringContainsString('Thursday', (string) $body);
        $this->assertSame($prompt->id, $again->id);
        $this->assertSame(1, \App\Models\RenewalPrompt::query()->count());
    }

    public function test_month_end_partial_payment_and_pickup_ticket_are_separate(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $bill = Bill::query()->first();
        $bill->update(['issue_date' => '2026-11-25', 'due_date' => '2026-11-30', 'amount_cents' => 10000]);
        app(BillingService::class)->receive($bill, $world['user'], 4000, 'cash', '2026-11-28');
        app(BillingService::class)->receive($bill->fresh(), $world['user'], 6000, 'e_transfer', '2026-12-02');
        PickupOrder::create([
            'merchant_id' => $world['merchant']->id,
            'location_id' => $world['location']->id,
            'customer_id' => $plan->customer_id,
            'idempotency_key' => 'pickup-not-cash',
            'quoted_total_cents' => 2000,
            'pickup_name' => 'Alex',
            'pickup_at' => '2026-11-26 16:00:00',
            'pay_at_pickup' => true,
        ]);

        $reports = app(CollectionReport::class);
        $november = $reports->forMerchant($world['merchant'], '2026-11', CarbonImmutable::parse('2026-12-15', 'America/Vancouver'));
        $december = $reports->forMerchant($world['merchant'], '2026-12', CarbonImmutable::parse('2026-12-15', 'America/Vancouver'));

        $this->assertSame(10000, $november->billedCents);
        $this->assertSame(4000, $november->collectedCents);
        $this->assertSame(6000, $november->outstandingCents);
        $this->assertSame(6000, $december->collectedCents);
        $this->assertSame(0, $december->billedCents);
    }

    public function test_opt_out_blocks_a_reminder_and_full_payment_cancels_it(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $bill = Bill::query()->first();
        $bill->update(['due_date' => '2026-11-30']);
        $plan->customer->permission->update([
            'proactive_allowed' => false,
            'opted_out_at' => now(),
            'source' => 'whatsapp',
        ]);

        $count = app(PaymentReminderService::class)->dispatchDue(CarbonImmutable::parse('2026-11-30 09:00', 'America/Vancouver')->utc());
        $this->assertSame(0, $count);

        NotificationPermission::query()->update(['proactive_allowed' => true, 'opted_out_at' => null]);
        app(BillingService::class)->receive($bill, $world['user'], 10000, 'cash', '2026-11-30');
        $count = app(PaymentReminderService::class)->dispatchDue(CarbonImmutable::parse('2026-11-30 09:00', 'America/Vancouver')->utc());
        $this->assertSame(0, $count);
        $this->assertSame(0, $bill->fresh()->balanceCents());
    }

    public function test_i_paid_opens_a_task_and_does_not_record_cash(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');

        $this->message($world['account']->phone_number_id, '+16045550123', 'I paid', '2026-11-26 11:00:00');

        $this->assertSame(1, PaymentTask::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_takeover_silences_automation_and_a_staff_reply_changes_nothing(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $this->message($world['account']->phone_number_id, '+16045550123', 'hello there', '2026-11-26 11:00:00');
        $conversation = Conversation::query()->first();
        $conversation->update(['status' => 'staff', 'taken_over_by' => $world['user']->id]);

        $this->message($world['account']->phone_number_id, '+16045550123', 'skip friday lunch', '2026-11-26 11:05:00');
        $this->assertSame('scheduled', $this->entry($plan, '2026-11-27', 'lunch')->status);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/inbox/'.$conversation->id.'/reply', ['body' => 'We can do Friday.']);
        $this->assertSame(6, $plan->fresh()->portion_credits_remaining);
    }

    public function test_retrying_a_failed_reply_does_not_repeat_a_skip(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        config(['services.whatsapp.fail' => true]);
        $this->message($world['account']->phone_number_id, '+16045550123', 'skip friday lunch', '2026-11-26 10:00:00');
        config(['services.whatsapp.fail' => false]);
        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-26 10:02:00');
        $credits = $plan->fresh()->portion_credits_remaining;
        $notification = \App\Models\OutboundNotification::query()->where('status', 'failed')->first();
        $this->assertNotNull($notification);

        $notification->update(['status' => 'queued', 'failure_reason' => null]);
        app(\App\Domain\Messaging\OutboundSender::class)->send($notification->fresh());

        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame($credits, $plan->fresh()->portion_credits_remaining);
        $this->assertSame(1, ScheduleEntry::query()->where('status', 'skipped')->count());
    }

    public function test_webhook_rejects_a_bad_signature_and_dedupes_a_good_one(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($world['merchant']);
        $body = $this->payload($world['account']->phone_number_id, '+16045550199', 'wamid.1', 'one latte, large, oat, pickup tomorrow at 12pm', '2026-11-26 10:00:00');

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=nope'], $body)
            ->assertUnauthorized();
        $this->assertSame(0, WebhookEvent::query()->count());

        $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->signed($body), $body)->assertOk();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->signed($body), $body)->assertOk();

        $this->assertSame(1, InboundMessage::query()->count());
        $this->assertSame(1, ProposedAction::query()->count());
    }

    public function test_a_ready_pickup_is_one_unpaid_order_and_a_second_yes_does_not_repeat_it(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($world['merchant']);
        Customer::create([
            'merchant_id' => $world['merchant']->id,
            'phone_e164' => '+16045550199',
            'name' => 'Alex Chen',
            'clover_customer_id' => 'CUS_ALEX',
        ]);
        $gateway = app(SimulatedCloverGateway::class);

        $this->message($world['account']->phone_number_id, '+16045550199', 'one latte, large, oat, pickup tomorrow at 12pm', '2026-11-26 10:00:00');
        $this->message($world['account']->phone_number_id, '+16045550199', 'yes', '2026-11-26 10:01:00');
        $this->message($world['account']->phone_number_id, '+16045550199', 'yes', '2026-11-26 10:02:00');

        $order = PickupOrder::query()->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->pay_at_pickup);
        $this->assertSame(662, $order->quoted_total_cents);
        $this->assertSame(1, $gateway->creates);
        $this->assertSame(1, $gateway->prints);
        $this->assertSame(1, $order->printEvents()->count());
        $this->assertSame('Alex Chen', $order->pickup_name);
        $this->assertSame('acknowledged', $order->fresh()->dispatch_status);
    }

    public function test_a_timeout_reconciles_one_order_and_a_reprint_is_marked(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($world['merchant']);
        Customer::create([
            'merchant_id' => $world['merchant']->id,
            'phone_e164' => '+16045550199',
            'name' => 'Alex Chen',
        ]);
        $gateway = app(SimulatedCloverGateway::class);
        $gateway->uncertainOnce = true;
        $gateway->printStatus = 'unknown';

        $this->message($world['account']->phone_number_id, '+16045550199', 'one latte, large, oat, pickup tomorrow at 12pm', '2026-11-26 10:00:00');
        $this->message($world['account']->phone_number_id, '+16045550199', 'yes', '2026-11-26 10:01:00');

        $this->assertSame(1, PickupOrder::query()->count());
        $this->assertSame(1, $gateway->creates);
        $order = PickupOrder::query()->first();
        $this->assertNotNull($order->clover_order_id);
        $this->assertSame('unknown', $order->dispatch_status);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/pickup/'.$order->id.'/reprint');
        $this->assertSame(1, PickupOrder::query()->count());
        $this->assertTrue($order->printEvents()->where('marked_copy', true)->exists());
    }

    public function test_a_sold_out_item_requires_a_new_confirmation(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $item = PilotWorld::catalog($world['merchant']);
        Customer::create(['merchant_id' => $world['merchant']->id, 'phone_e164' => '+16045550199', 'name' => 'Alex Chen', 'clover_customer_id' => 'CUS_ALEX']);
        $this->message($world['account']->phone_number_id, '+16045550199', 'one latte, large, oat, pickup tomorrow at 12pm', '2026-11-26 10:00:00');
        $item->update(['available' => false]);
        $gateway = app(SimulatedCloverGateway::class);

        $this->message($world['account']->phone_number_id, '+16045550199', 'yes', '2026-11-26 10:05:00');

        $this->assertSame(0, $gateway->creates);
        $this->assertSame(0, PickupOrder::query()->count());
    }

    public function test_kitchen_cannot_open_dues_and_owner_can(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $kitchen = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $kitchen->id,
            'role' => StaffRole::Kitchen,
        ]);

        $this->actingAs($kitchen)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/dues')->assertForbidden();
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/dues?month=2026-11')->assertOk()->assertSee('Billed');
    }

    public function test_an_open_ended_pause_stays_paused_until_a_restart_is_confirmed(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');

        $this->message($world['account']->phone_number_id, '+16045550123', 'pause until further notice', '2026-11-26 10:00:00');
        $this->assertFalse($plan->fresh()->pause_open_ended);
        $this->message($world['account']->phone_number_id, '+16045550123', 'yes', '2026-11-26 10:01:00');
        $this->assertTrue($plan->fresh()->pause_open_ended);
        $this->assertSame('paused', $plan->fresh()->status->value);
    }

    public function test_ambiguous_punjabi_on_a_hybrid_number_asks_which_module(): void
    {
        $world = PilotWorld::merchant('Hybrid', ['cafe', 'tiffin']);
        PilotWorld::catalog($world['merchant']);

        $this->message($world['account']->phone_number_id, '+16045550123', 'kal dekh lo ji', '2026-11-26 10:00:00');

        $this->assertSame(0, ProposedAction::query()->count());
        $this->assertSame(0, Subscription::query()->count());
        $reply = \App\Models\OutboundNotification::query()->first();
        $this->assertStringContainsString('Place a pickup order or manage your tiffin?', (string) $reply->body);
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function message(string $phoneNumberId, string $from, string $text, string $local): void
    {
        $utc = CarbonImmutable::parse($local, 'America/Vancouver')->utc();
        $body = $this->payload($phoneNumberId, $from, 'wamid.'.md5($from.$text.$local), $text, $local);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->signed($body), $body)->assertOk();
        $this->assertNotNull($utc);
    }

    private function payload(string $phoneNumberId, string $from, string $id, string $text, string $local): string
    {
        $timestamp = CarbonImmutable::parse($local, 'America/Vancouver')->utc()->timestamp;

        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => ltrim($from, '+'),
                            'id' => $id,
                            'timestamp' => (string) $timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ];
    }

    private function entry(Subscription $subscription, string $date, string $slot): ScheduleEntry
    {
        return ScheduleEntry::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('service_date', $date)
            ->where('slot', $slot)
            ->firstOrFail();
    }
}
