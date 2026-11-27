<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\MerchantUser;
use App\Models\TiffinMenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class TiffinMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tiffin_kitchen_plans_breakfast_lunch_and_dinner_by_diet(): void
    {
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $kitchen = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $kitchen->id,
            'role' => StaffRole::Kitchen,
        ]);

        $this->actingAs($kitchen)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/tiffin-menu')->assertOk()->assertSee('Breakfast')->assertSee('Non-veg');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/tiffin-menu', [
                'day_of_week' => 1,
                'slot' => 'breakfast',
                'diet' => 'veg',
                'name' => 'Poha',
            ])->assertRedirect('/tiffin-menu');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/tiffin-menu', [
                'day_of_week' => 1,
                'slot' => 'dinner',
                'diet' => 'non_veg',
                'name' => 'Butter chicken',
            ])->assertRedirect('/tiffin-menu');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/tiffin-menu')->assertOk()->assertSee('Poha')->assertSee('Butter chicken');

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/packing?date=2026-11-23')->assertOk()->assertSee('Monday menu')->assertSee('Poha')->assertSee('Butter chicken');
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/packing?date=2026-11-24')->assertOk()->assertDontSee('Poha');

        $other = PilotWorld::merchant('North Kitchen', ['tiffin']);
        $item = TiffinMenuItem::query()->where('name', 'Poha')->first();
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->delete('/tiffin-menu/'.$item->id)->assertNotFound();
        $this->assertNotNull($item->fresh());

        $cafe = PilotWorld::merchant('Surrey Café', ['cafe']);
        $this->actingAs($cafe['user'])->withSession(['merchant_id' => $cafe['merchant']->id])
            ->get('/tiffin-menu')->assertNotFound();
    }

    public function test_a_menu_dish_can_include_a_photo_and_removing_it_deletes_the_file(): void
    {
        Storage::fake('public');
        $world = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $photo = UploadedFile::fake()->image('poha.jpg', 80, 80);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->post('/tiffin-menu', [
                'day_of_week' => 1,
                'slot' => 'breakfast',
                'diet' => 'veg',
                'name' => 'Poha plate',
                'photo' => $photo,
            ])->assertRedirect('/tiffin-menu');

        $item = TiffinMenuItem::query()->where('name', 'Poha plate')->first();
        $this->assertNotNull($item);
        $this->assertNotNull($item->photo_path);
        Storage::disk('public')->assertExists($item->photo_path);

        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/tiffin-menu')->assertOk()->assertSee('dish-photo', false)->assertSee('Poha plate');
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/packing?date=2026-11-23')->assertOk()->assertSee('Poha plate')->assertSee('menu-dish', false);

        $path = $item->photo_path;
        $this->actingAs($world['user'])->withSession(['merchant_id' => $world['merchant']->id])
            ->delete('/tiffin-menu/'.$item->id)->assertRedirect('/tiffin-menu');
        $this->assertNull($item->fresh());
        Storage::disk('public')->assertMissing($path);
    }
}
