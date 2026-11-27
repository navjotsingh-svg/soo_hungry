<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class PickupAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tiffin_workspace_opens_pickup_on_a_cafe_the_owner_can_use(): void
    {
        $cafe = PilotWorld::merchant('Surrey Café', ['cafe']);
        $tiffin = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        MerchantUser::create([
            'merchant_id' => $cafe['merchant']->id,
            'user_id' => $tiffin['user']->id,
            'role' => StaffRole::Owner,
        ]);

        $this->actingAs($tiffin['user'])->withSession(['merchant_id' => $tiffin['merchant']->id])
            ->get('/pickup')
            ->assertOk()
            ->assertSee('Open Surrey Café')
            ->assertDontSee('No pickup orders yet');

        $this->actingAs($tiffin['user'])->withSession(['merchant_id' => $tiffin['merchant']->id])
            ->post('/merchant', [
                'merchant_id' => $cafe['merchant']->id,
                'next' => 'pickup',
            ])->assertRedirect('/pickup');

        $this->actingAs($tiffin['user'])->withSession(['merchant_id' => $cafe['merchant']->id])
            ->get('/pickup')
            ->assertOk()
            ->assertSee('No pickup orders yet');
    }
}
