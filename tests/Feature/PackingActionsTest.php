<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\ScheduleEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class PackingActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_packed_marks_one_meal_and_stays_on_that_date(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 6);
        $lunch = $this->entry($plan->id, '2026-11-27', 'lunch');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/'.$lunch->id.'/fulfill')
            ->assertRedirect('/packing?date=2026-11-27#list');

        $this->assertSame('fulfilled', $lunch->fresh()->status);
        $this->assertSame(5, $plan->fresh()->portion_credits_remaining);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/'.$lunch->id.'/fulfill')
            ->assertRedirect('/packing?date=2026-11-27#list');

        $this->assertSame(5, $plan->fresh()->portion_credits_remaining);
        $this->assertSame('scheduled', $this->entry($plan->id, '2026-11-27', 'dinner')->status);
    }

    public function test_staff_can_cancel_one_lunch_without_touching_dinner_or_the_credit(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 6);
        $lunch = $this->entry($plan->id, '2026-11-27', 'lunch');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/'.$lunch->id.'/skip')
            ->assertRedirect('/packing?date=2026-11-27#list');

        $this->assertSame('skipped', $lunch->fresh()->status);
        $this->assertSame(0, $lunch->fresh()->quantity);
        $this->assertSame('preserve', $lunch->fresh()->credit_treatment);
        $this->assertSame('scheduled', $this->entry($plan->id, '2026-11-27', 'dinner')->status);
        $this->assertSame(6, $plan->fresh()->portion_credits_remaining);
        $this->assertSame(1, Bill::query()->count());
    }

    public function test_staff_can_assign_days_and_meals_without_a_new_bill(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 6);
        $kept = $this->entry($plan->id, '2026-11-27', 'lunch');
        $kept->update(['status' => 'skipped', 'quantity' => 0, 'credit_treatment' => 'preserve']);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/customers/'.$plan->customer_id.'/assignment', [
                'date' => '2026-11-27',
                'days' => [1, 2, 3, 4, 5, 6],
                'breakfast' => 0,
                'lunch' => 1,
                'dinner' => 0,
                'diet' => 'veg',
            ])->assertRedirect('/packing?date=2026-11-27#assignments');

        $plan->refresh();
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map('intval', $plan->service_days));
        $this->assertSame(0, $plan->dinner_portions);
        $this->assertSame(6, $plan->portion_credits_remaining);
        $this->assertSame(1, Bill::query()->count());
        $this->assertSame('skipped', $kept->fresh()->status);
        $this->assertNull($this->entry($plan->id, '2026-11-27', 'dinner'));
        $saturday = $this->entry($plan->id, '2026-11-28', 'lunch');
        $this->assertNotNull($saturday);
        $this->assertSame('scheduled', $saturday->status);
        $this->assertNull($this->entry($plan->id, '2026-11-28', 'dinner'));

        $extra = Customer::create([
            'merchant_id' => $world['merchant']->id,
            'phone_e164' => '+16045550188',
            'name' => 'Rita Gill',
        ]);
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/customers/'.$extra->id.'/assignment', [
                'date' => '2026-11-27',
                'days' => [5],
                'breakfast' => 0,
                'lunch' => 0,
                'dinner' => 1,
                'diet' => 'non_veg',
            ])->assertRedirect('/packing?date=2026-11-27#assignments');

        $this->assertSame(0, Bill::query()->where('customer_id', $extra->id)->count());
        $rita = ScheduleEntry::query()->where('customer_id', $extra->id)->whereDate('service_date', '2026-11-27')->where('slot', 'dinner')->first();
        $this->assertNotNull($rita);
        $this->assertSame('scheduled', $rita->status);

        $other = PilotWorld::merchant('North Kitchen', ['tiffin']);
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->post('/packing/'.$saturday->id.'/skip')->assertNotFound();
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->post('/packing/customers/'.$extra->id.'/assignment', [
                'date' => '2026-11-27',
                'days' => [1],
                'lunch' => 1,
                'dinner' => 1,
                'diet' => 'veg',
            ])->assertNotFound();
    }

    public function test_today_and_tomorrow_can_be_opened_as_one_meal(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 6);
        $fridayLunch = $this->entry($plan->id, '2026-11-27', 'lunch');
        $fridayDinner = $this->entry($plan->id, '2026-11-27', 'dinner');

        $lunch = $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/packing?date=2026-11-27&slot=lunch');

        $lunch->assertOk();
        $lunch->assertSee('Today');
        $lunch->assertSee('Tomorrow');
        $lunch->assertSee('All meals');
        $lunch->assertSee('/packing/'.$fridayLunch->id.'/fulfill', false);
        $lunch->assertDontSee('/packing/'.$fridayDinner->id.'/fulfill', false);
        $lunch->assertDontSee('No lunch orders for this date.');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/packing?date=2026-11-27&slot=breakfast')
            ->assertOk()
            ->assertSee('No breakfast orders for this date.');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/'.$fridayLunch->id.'/fulfill', ['slot' => 'lunch'])
            ->assertRedirect('/packing?date=2026-11-27&slot=lunch#list');
    }

    public function test_staff_can_skip_lunch_on_saturday_and_keep_the_other_meals(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($world['merchant'], $world['user'], '+16045550123', credits: 6);
        $slots = [];
        foreach ([1, 2, 3, 4, 5] as $day) {
            $slots[$day] = ['lunch', 'dinner'];
        }
        $slots[6] = ['dinner'];

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/packing/customers/'.$plan->customer_id.'/assignment', [
                'date' => '2026-11-28',
                'slots_present' => '1',
                'slots' => $slots,
                'breakfast' => 0,
                'lunch' => 1,
                'dinner' => 1,
                'diet' => 'veg',
            ])->assertRedirect('/packing?date=2026-11-28#assignments');

        $plan->refresh();
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map('intval', $plan->service_days));
        $this->assertFalse($plan->serves(6, 'lunch'));
        $this->assertTrue($plan->serves(6, 'dinner'));
        $this->assertTrue($plan->serves(5, 'lunch'));
        $this->assertSame(6, $plan->portion_credits_remaining);
        $this->assertSame(1, Bill::query()->count());
        $this->assertNull($this->entry($plan->id, '2026-11-28', 'lunch'));
        $this->assertSame('scheduled', $this->entry($plan->id, '2026-11-28', 'dinner')->status);
        $this->assertSame('scheduled', $this->entry($plan->id, '2026-11-27', 'lunch')->status);
    }

    private function entry(int $subscriptionId, string $date, string $slot): ?ScheduleEntry
    {
        return ScheduleEntry::query()
            ->where('subscription_id', $subscriptionId)
            ->whereDate('service_date', $date)
            ->where('slot', $slot)
            ->first();
    }
}
