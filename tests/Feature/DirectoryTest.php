<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Bill;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\MerchantUser;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_keep_a_customer_without_starting_a_plan(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/customers', [
                'name' => 'Rita Gill',
                'phone' => '6045550188',
                'address' => '12 128 St, Surrey',
                'language' => 'en',
            ])->assertRedirect('/customers');

        $customer = Customer::query()->where('phone_e164', '+16045550188')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Rita Gill', $customer->name);
        $this->assertSame(0, Subscription::query()->count());

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->put('/customers/'.$customer->id, [
                'name' => 'Rita Kaur',
                'phone' => '(604) 555-0188',
                'address' => '12 128 St, Surrey',
                'language' => 'pa-Latn',
            ])->assertRedirect('/customers/'.$customer->id.'/edit');

        $this->assertSame('Rita Kaur', $customer->fresh()->name);
        $this->assertSame('pa-Latn', $customer->fresh()->language);
    }

    public function test_a_tiffin_customer_can_be_added_with_a_schedule(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/customers/new')->assertOk()->assertSee('Tiffin schedule');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/customers/new', [
                'name' => 'Blank Grid',
                'phone' => '6045550193',
                'language' => 'en',
                'slots_present' => '1',
                'diet' => 'veg',
                'breakfast' => 0,
                'lunch' => 0,
                'dinner' => 0,
            ])->assertRedirect('/customers');

        $blankId = Customer::query()->where('phone_e164', '+16045550193')->value('id');
        $this->assertNotNull($blankId);
        $this->assertSame(0, Subscription::query()->where('customer_id', $blankId)->count());

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/customers', [
                'name' => 'Rita Gill',
                'phone' => '6045550188',
                'address' => '12 128 St, Surrey',
                'language' => 'en',
                'schedule_days' => [1, 5],
                'breakfast' => 1,
                'lunch' => 1,
                'dinner' => 1,
                'diet' => 'veg',
            ])->assertRedirect('/customers');

        $customer = Customer::query()->where('phone_e164', '+16045550188')->first();
        $this->assertNotNull($customer);
        $plan = Subscription::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame([1, 5], array_map('intval', $plan->service_days));
        $this->assertSame(1, $plan->breakfast_portions);
        $this->assertSame(1, $plan->lunch_portions);
        $this->assertSame(1, $plan->dinner_portions);
        $this->assertSame('veg', $plan->diet);
        $this->assertSame(0, Bill::query()->where('customer_id', $customer->id)->count());
        $this->assertTrue(ScheduleEntry::query()->where('customer_id', $customer->id)->where('slot', 'breakfast')->where('diet', 'veg')->where('status', 'scheduled')->exists());
        $this->assertTrue(ScheduleEntry::query()->where('customer_id', $customer->id)->where('slot', 'lunch')->where('status', 'scheduled')->exists());

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/customers', [
                'name' => 'No Schedule',
                'phone' => '6045550189',
                'language' => 'en',
                'lunch' => 0,
                'dinner' => 0,
            ])->assertRedirect('/customers');

        $plain = Customer::query()->where('phone_e164', '+16045550189')->first();
        $this->assertSame(0, Subscription::query()->where('customer_id', $plain->id)->count());
    }

    public function test_a_phone_cannot_be_used_twice_in_one_workspace(): void
    {
        $first = PilotWorld::merchant('Surrey Café', ['cafe']);
        $second = PilotWorld::merchant('North Kitchen', ['tiffin']);
        Customer::create([
            'merchant_id' => $first['merchant']->id,
            'phone_e164' => '+16045550188',
            'name' => 'Rita Gill',
        ]);

        $this->actingAs($first['user'])->withSession(['merchant_id' => $first['merchant']->id])
            ->from('/customers/new')
            ->post('/customers', [
                'name' => 'Someone Else',
                'phone' => '6045550188',
                'language' => 'en',
            ])->assertRedirect('/customers/new')->assertSessionHasErrors('phone');

        $this->actingAs($second['user'])->withSession(['merchant_id' => $second['merchant']->id])
            ->post('/customers', [
                'name' => 'Rita Gill',
                'phone' => '6045550188',
                'language' => 'en',
            ])->assertRedirect('/customers');

        $this->assertSame(1, Customer::query()->where('merchant_id', $second['merchant']->id)->count());
        $this->actingAs($second['user'])->withSession(['merchant_id' => $second['merchant']->id])
            ->get('/customers')->assertOk()->assertSee('Rita Gill');
        $this->actingAs($first['user'])->withSession(['merchant_id' => $first['merchant']->id])
            ->get('/customers/'.Customer::query()->where('merchant_id', $second['merchant']->id)->value('id').'/edit')
            ->assertNotFound();
    }

    public function test_kitchen_cannot_open_the_directory_and_a_tiffin_kitchen_has_no_menu(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $kitchen = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $kitchen->id,
            'role' => StaffRole::Kitchen,
        ]);

        $this->actingAs($kitchen)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/customers')->assertForbidden();
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/customers')->assertOk()->assertSee('Add customer');
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/menu')->assertNotFound();
    }

    public function test_staff_can_add_and_edit_a_menu_item(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($world['merchant']);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/menu', [
                'name' => 'Chai',
                'price' => '3.50',
                'tax_percent' => '5',
                'available' => '1',
                'groups' => [
                    ['name' => 'Size', 'required' => '1', 'options' => "Small | 0\nLarge | 0.50"],
                    ['name' => '', 'options' => ''],
                ],
            ])->assertRedirect('/menu');

        $item = CatalogItem::query()->where('name', 'Chai')->first();
        $this->assertNotNull($item);
        $this->assertSame(350, $item->price_cents);
        $this->assertSame(500, $item->tax_rate_bps);
        $this->assertTrue($item->available);
        $this->assertSame('size', $item->modifier_groups[0]['id']);
        $this->assertTrue($item->modifier_groups[0]['required']);
        $this->assertSame(50, $item->modifier_groups[0]['options'][1]['price_cents']);
        $this->assertNotNull($world['merchant']->cloverConnection->fresh()->catalog_synced_at);

        $other = PilotWorld::merchant('North Kitchen', ['tiffin']);
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->get('/menu/'.$item->id.'/edit')->assertNotFound();

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->put('/menu/'.$item->id, [
                'name' => 'Masala chai',
                'price' => '4.00',
                'tax_percent' => '5',
            ])->assertRedirect('/menu/'.$item->id.'/edit');

        $this->assertSame('Masala chai', $item->fresh()->name);
        $this->assertSame(400, $item->fresh()->price_cents);
        $this->assertFalse($item->fresh()->available);
        $this->assertSame([], $item->fresh()->modifier_groups);
    }
}
