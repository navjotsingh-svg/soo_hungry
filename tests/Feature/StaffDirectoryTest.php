<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_add_edit_and_set_a_password_for_staff(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $session = ['merchant_id' => $world['merchant']->id];

        $this->actingAs($world['user'])->withSession($session)
            ->get('/staff')->assertOk()->assertSee('Add staff');

        $this->actingAs($world['user'])->withSession($session)
            ->post('/staff', [
                'name' => 'Simran Gill',
                'email' => 'simran@soohungry.test',
                'phone' => '6045550188',
                'role' => 'service',
                'active' => '1',
                'password' => 'counter-pass',
                'password_confirmation' => 'counter-pass',
            ])->assertRedirect('/staff');

        $staff = User::query()->where('email', 'simran@soohungry.test')->first();
        $this->assertNotNull($staff);
        $this->assertTrue(Hash::check('counter-pass', $staff->password));
        $this->assertSame(StaffRole::Service, $staff->memberships()->first()->role);

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$staff->id, [
                'name' => 'Simran Kaur',
                'email' => 'simran@soohungry.test',
                'role' => 'kitchen',
                'active' => '1',
            ])->assertRedirect('/staff/'.$staff->id.'/edit');
        $this->assertSame('Simran Kaur', $staff->fresh()->name);
        $this->assertSame(StaffRole::Kitchen, $staff->memberships()->first()->fresh()->role);

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$staff->id.'/password', [
                'password' => 'new-counter',
                'password_confirmation' => 'new-counter',
            ])->assertRedirect('/staff/'.$staff->id.'/edit');
        $this->assertTrue(Hash::check('new-counter', $staff->fresh()->password));

        $this->actingAs($world['user'])->withSession($session)->post('/logout');
        $this->post('/login', [
            'email' => 'simran@soohungry.test',
            'password' => 'new-counter',
        ])->assertRedirect('/today');

        $other = PilotWorld::merchant('North Kitchen', ['tiffin']);
        $this->actingAs($other['user'])->withSession(['merchant_id' => $other['merchant']->id])
            ->get('/staff/'.$staff->id.'/edit')->assertNotFound();

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$world['user']->id, [
                'name' => $world['user']->name,
                'email' => $world['user']->email,
                'role' => 'service',
                'active' => '1',
            ])->assertSessionHasErrors('role');
        $this->assertSame(StaffRole::Owner, MerchantUser::query()->where('user_id', $world['user']->id)->first()->role);
    }

    public function test_service_cannot_open_staff_and_anyone_can_change_their_own_password(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $service = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $service->id,
            'role' => StaffRole::Service,
        ]);

        $this->actingAs($service)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/staff')->assertForbidden();

        $this->actingAs($service)->withSession(['merchant_id' => $world['merchant']->id])
            ->put('/password', [
                'current_password' => 'wrong',
                'password' => 'service-pass',
                'password_confirmation' => 'service-pass',
            ])->assertSessionHasErrors('current_password');

        $this->actingAs($service)->withSession(['merchant_id' => $world['merchant']->id])
            ->from('/password')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'service-pass',
                'password_confirmation' => 'service-pass',
            ])->assertRedirect('/password');
        $this->assertTrue(Hash::check('service-pass', $service->fresh()->password));
    }

    public function test_an_inactive_staff_member_cannot_sign_in_and_the_last_owner_stays_active(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $session = ['merchant_id' => $world['merchant']->id];
        $service = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $service->id,
            'role' => StaffRole::Service,
        ]);

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$service->id, [
                'name' => $service->name,
                'email' => $service->email,
                'role' => 'service',
                'active' => '0',
            ])->assertRedirect('/staff/'.$service->id.'/edit');
        $this->assertFalse($service->memberships()->first()->fresh()->active);

        $this->actingAs($world['user'])->withSession($session)->post('/logout');
        $this->post('/login', [
            'email' => $service->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($service)->withSession($session)
            ->get('/today')->assertRedirect('/login');

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$world['user']->id, [
                'name' => $world['user']->name,
                'email' => $world['user']->email,
                'role' => 'owner',
                'active' => '0',
            ])->assertSessionHasErrors('active');
        $this->assertTrue(MerchantUser::query()->where('user_id', $world['user']->id)->first()->active);

        $this->actingAs($world['user'])->withSession($session)
            ->put('/staff/'.$service->id, [
                'name' => $service->name,
                'email' => $service->email,
                'role' => 'service',
                'active' => '1',
            ])->assertRedirect('/staff/'.$service->id.'/edit');
        $this->actingAs($world['user'])->withSession($session)->post('/logout');
        $this->post('/login', [
            'email' => $service->email,
            'password' => 'password',
        ])->assertRedirect('/today');
    }
}
