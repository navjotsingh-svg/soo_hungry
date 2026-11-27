<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\MerchantUser;
use App\Models\OutboundNotification;
use App\Models\ScheduleEntry;
use App\Models\TiffinMenuItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PilotWorld;
use Tests\TestCase;

class WhatsAppConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_connect_a_meta_number_and_replies_use_that_token(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $session = ['merchant_id' => $world['merchant']->id];
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.live']]]),
        ]);

        $this->actingAs($world['user'])->withSession($session)
            ->get('/whatsapp')
            ->assertOk()
            ->assertSee('Not connected')
            ->assertSee('Callback URL');

        $this->actingAs($world['user'])->withSession($session)
            ->put('/whatsapp', [
                'display_number' => '6045550100',
                'phone_number_id' => 'PN_META_1',
                'waba_id' => 'WABA1',
                'access_token' => 'meta-token-1234567890',
            ])->assertRedirect('/whatsapp');

        $account = $world['account']->fresh();
        $this->assertSame('+16045550100', $account->display_number);
        $this->assertSame('PN_META_1', $account->phone_number_id);
        $this->assertSame('meta-token-1234567890', $account->access_token);
        $this->assertSame('connected', $account->status);

        $this->actingAs($world['user'])->withSession($session)
            ->get('/whatsapp')
            ->assertOk()
            ->assertSee('Connected')
            ->assertDontSee('meta-token-1234567890');

        $this->message('PN_META_1', '+16045550199', 'menu', '2026-11-26 10:00:00');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'https://graph.facebook.com/')
                && str_contains($request->url(), 'PN_META_1/messages')
                && $request->hasHeader('Authorization', 'Bearer meta-token-1234567890');
        });

        $this->actingAs($world['user'])->withSession($session)
            ->put('/whatsapp', [
                'display_number' => '6045550100',
                'phone_number_id' => 'PN_META_1',
                'waba_id' => 'WABA1',
                'access_token' => '',
            ])->assertRedirect('/whatsapp');
        $this->assertSame('meta-token-1234567890', $account->fresh()->access_token);

        $this->actingAs($world['user'])->withSession($session)
            ->post('/whatsapp/disconnect')
            ->assertRedirect('/whatsapp');
        $this->assertNull($account->fresh()->access_token);
        $this->assertSame('sandbox', $account->fresh()->status);
    }

    public function test_an_owner_can_sign_in_with_meta_instead_of_pasting_a_token(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $session = ['merchant_id' => $world['merchant']->id];

        $this->actingAs($world['user'])->withSession($session)
            ->get('/whatsapp')
            ->assertOk()
            ->assertSee('Connect with Meta')
            ->assertSee('Cloud API')
            ->assertSee('Coexistence')
            ->assertSee('id="meta-connect-button"', false)
            ->assertSee('Save Meta app');

        $this->actingAs($world['user'])->withSession($session)
            ->post('/whatsapp/setup', [
                'meta_app_id' => '1234567890',
                'meta_config_id' => '99887766',
            ])->assertRedirect('/whatsapp');

        $this->actingAs($world['user'])->withSession($session)
            ->get('/whatsapp')
            ->assertOk()
            ->assertSee('id="meta-connect-button"', false)
            ->assertDontSee('test-secret');

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'exchanged-token-123456']),
            'https://graph.facebook.com/*' => Http::response(['display_phone_number' => '+1 604-555-0188']),
        ]);

        $this->actingAs($world['user'])->withSession($session)
            ->post('/whatsapp/meta', [
                'code' => 'signup-code',
                'phone_number_id' => '1099',
                'waba_id' => '555',
            ])->assertRedirect('/whatsapp');

        $account = $world['account']->fresh();
        $this->assertSame('exchanged-token-123456', $account->access_token);
        $this->assertSame('1099', $account->phone_number_id);
        $this->assertSame('+16045550188', $account->display_number);
        $this->assertSame('555', $account->waba_id);
        $this->assertSame('connected', $account->status);

        $this->actingAs($world['user'])->withSession($session)
            ->get('/whatsapp')
            ->assertOk()
            ->assertDontSee('exchanged-token-123456');
    }

    public function test_connect_with_meta_opens_the_meta_website(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $session = ['merchant_id' => $world['merchant']->id];

        $this->actingAs($world['user'])->withSession($session)
            ->post('/whatsapp/meta/start', [
                'meta_app_id' => '1234567890',
                'meta_config_id' => '99887766',
                'signup_mode' => 'coexistence',
            ])->assertRedirect();

        $location = (string) $this->actingAs($world['user'])->withSession($session)
            ->post('/whatsapp/meta/start', [
                'signup_mode' => 'cloud',
            ])->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://www.facebook.com/', $location);
        $this->assertStringContainsString('client_id=1234567890', $location);
        $this->assertStringContainsString('config_id=99887766', $location);
        $this->assertStringContainsString(urlencode(route('whatsapp.meta.callback')), $location);
    }

    public function test_service_cannot_open_the_whatsapp_connection(): void
    {
        $world = PilotWorld::merchant('Surrey Café', ['cafe']);
        $service = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $world['merchant']->id,
            'user_id' => $service->id,
            'role' => StaffRole::Service,
        ]);

        $this->actingAs($service)->withSession(['merchant_id' => $world['merchant']->id])
            ->get('/whatsapp')
            ->assertForbidden();
    }

    public function test_menu_and_today_lunch_not_needed_are_understood(): void
    {
        $cafe = PilotWorld::merchant('Surrey Café', ['cafe']);
        PilotWorld::catalog($cafe['merchant']);
        $this->message($cafe['account']->phone_number_id, '+16045550199', 'menu', '2026-11-26 10:00:00');
        $menu = OutboundNotification::query()->latest('id')->first();
        $this->assertStringContainsString('Latte $4.50', (string) $menu->body);

        $tiffin = PilotWorld::merchant('Lets Tiffin', ['tiffin']);
        $plan = PilotWorld::plan($tiffin['merchant'], $tiffin['user'], '+16045550123');
        TiffinMenuItem::create([
            'merchant_id' => $tiffin['merchant']->id,
            'day_of_week' => 1,
            'slot' => 'lunch',
            'diet' => 'veg',
            'name' => 'Rajma',
        ]);
        $this->message($tiffin['account']->phone_number_id, '+16045550123', 'menu', '2026-11-23 09:00:00');
        $tiffinMenu = OutboundNotification::query()->where('merchant_id', $tiffin['merchant']->id)->latest('id')->first();
        $this->assertStringContainsString('Monday Lunch: Rajma', (string) $tiffinMenu->body);

        $this->message($tiffin['account']->phone_number_id, '+16045550123', 'today lunch not needed', '2026-11-23 10:00:00');
        $reply = OutboundNotification::query()->where('merchant_id', $tiffin['merchant']->id)->latest('id')->first();
        $this->assertStringContainsString('The owner has to approve it', (string) $reply->body);
        $this->assertStringContainsString('Nothing changes until then', (string) $reply->body);
        $this->assertSame('scheduled', ScheduleEntry::query()
            ->where('subscription_id', $plan->id)
            ->whereDate('service_date', '2026-11-23')
            ->where('slot', 'lunch')
            ->value('status'));
    }

    private function message(string $phoneNumberId, string $from, string $text, string $local): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => ltrim($from, '+'),
                            'id' => 'wamid.'.md5($from.$text.$local),
                            'timestamp' => (string) CarbonImmutable::parse($local, 'America/Vancouver')->utc()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();
    }
}
