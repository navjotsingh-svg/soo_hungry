<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Bill;
use App\Models\IntegrationAlert;
use App\Models\MerchantUser;
use App\Models\OutboundNotification;
use App\Models\PaymentReminder;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class PilotGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dues_shows_the_last_reminder_and_a_failed_delivery(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123');
        $bill = Bill::query()->where('subscription_id', $plan->id)->first();
        $notice = OutboundNotification::create([
            'merchant_id' => $world['merchant']->id,
            'customer_id' => $plan->customer_id,
            'kind' => 'payment_reminder',
            'body' => 'Amount due.',
            'status' => 'failed',
            'failure_reason' => 'The template was rejected.',
            'idempotency_key' => 'reminder-test',
        ]);
        PaymentReminder::create([
            'merchant_id' => $world['merchant']->id,
            'bill_id' => $bill->id,
            'customer_id' => $plan->customer_id,
            'due_on' => $bill->due_date->toDateString(),
            'status' => 'failed',
            'outbound_notification_id' => $notice->id,
        ]);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/dues')
            ->assertOk()
            ->assertSee('Reminders')
            ->assertSee('Jaspreet Kaur')
            ->assertSee('Failed')
            ->assertSee('The template was rejected.');
    }

    public function test_staff_can_enter_opening_credits_and_an_unpaid_balance(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $session = ['merchant_id' => $world['merchant']->id];

        $this->actingAs($world['user'])->withSession($session)
            ->from('/customers/new')
            ->post('/customers', [
                'name' => 'Rita Gill',
                'phone' => '6045550188',
                'language' => 'en',
                'opening_balance' => '40.00',
            ])->assertRedirect('/customers/new')->assertSessionHasErrors('opening_due');

        $this->actingAs($world['user'])->withSession($session)
            ->post('/customers', [
                'name' => 'Rita Gill',
                'phone' => '6045550188',
                'address' => '12 128 St, Surrey',
                'language' => 'en',
                'opening_credits' => '4',
                'opening_balance' => '40.00',
                'opening_due' => '2026-11-30',
            ])->assertRedirect('/customers');

        $customer = \App\Models\Customer::query()->where('phone_e164', '+16045550188')->first();
        $plan = Subscription::query()->where('customer_id', $customer->id)->first();
        $this->assertSame(4, $plan->portion_credits_remaining);
        $this->assertSame(0, $plan->plan_price_cents);
        $bill = Bill::query()->where('customer_id', $customer->id)->where('cycle_key', 'opening')->first();
        $this->assertNotNull($bill);
        $this->assertSame(4000, $bill->amount_cents);
        $this->assertSame('2026-11-30', $bill->due_date->toDateString());
        $this->assertSame(0, \App\Models\ScheduleEntry::query()->where('customer_id', $customer->id)->count());

        $this->actingAs($world['user'])->withSession($session)
            ->get('/dues')->assertOk()->assertSee('Rita Gill')->assertSee('$40.00');
    }

    public function test_the_pilot_log_lists_alerts_and_stays_inside_the_merchant(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        IntegrationAlert::create([
            'merchant_id' => $world['merchant']->id,
            'integration' => 'printer',
            'message' => 'Print result unknown for CLV1.',
            'status' => 'open',
            'created_at' => now(),
        ]);
        $kitchen = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $kitchen->id,
            'role' => StaffRole::Kitchen,
        ]);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/evidence')
            ->assertOk()
            ->assertSee('Pilot log')
            ->assertSee('Print result unknown for CLV1.');

        $this->actingAs($kitchen)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/evidence')->assertForbidden();

        $other = PilotWorld::merchant('North Kitchen', ['tiffin']);
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->get('/evidence')->assertOk()->assertDontSee('Print result unknown for CLV1.');
    }
}
