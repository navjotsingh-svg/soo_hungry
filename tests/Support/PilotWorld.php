<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tiffin\ScheduleGenerator;
use App\Domain\Tiffin\SubscriptionEnrollment;
use App\Enums\PlanKind;
use App\Enums\StaffRole;
use App\Models\BusinessHour;
use App\Models\CatalogItem;
use App\Models\CloverConnection;
use App\Models\Customer;
use App\Models\CutoffPolicy;
use App\Models\Location;
use App\Models\Merchant;
use App\Models\MerchantModule;
use App\Models\MerchantUser;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;

final class PilotWorld
{
    /**
     * @param  list<string>  $modules
     * @return array{merchant: Merchant, user: User, account: WhatsAppAccount, location: Location}
     */
    public static function merchant(string $name, array $modules, string $cutoff = '20:00:00'): array
    {
        $merchant = Merchant::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->random(4),
            'timezone' => 'America/Vancouver',
            'currency' => 'CAD',
            'reminder_local_time' => '09:00:00',
        ]);
        $location = Location::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'lead_time_minutes' => 20,
        ]);
        foreach (range(1, 7) as $day) {
            BusinessHour::create([
                'location_id' => $location->id,
                'day_of_week' => $day,
                'opens_at' => '07:30:00',
                'closes_at' => '17:00:00',
                'is_closed' => false,
            ]);
        }
        foreach (['cafe', 'tiffin'] as $module) {
            MerchantModule::create([
                'merchant_id' => $merchant->id,
                'module' => $module,
                'enabled' => in_array($module, $modules, true),
            ]);
        }
        if (in_array('tiffin', $modules, true)) {
            CutoffPolicy::create([
                'merchant_id' => $merchant->id,
                'cutoff_local_time' => $cutoff,
                'grace_minutes' => 10,
            ]);
        }
        $account = WhatsAppAccount::create([
            'merchant_id' => $merchant->id,
            'location_id' => $location->id,
            'phone_number_id' => 'PN'.str()->upper(str()->random(8)),
            'display_number' => '+1604555'.random_int(1000, 9999),
            'status' => 'sandbox',
        ]);
        $user = User::factory()->create();
        MerchantUser::create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => StaffRole::Owner,
        ]);

        return compact('merchant', 'user', 'account', 'location');
    }

    public static function catalog(Merchant $merchant): CatalogItem
    {
        $connection = CloverConnection::create([
            'merchant_id' => $merchant->id,
            'location_id' => $merchant->locations()->first()->id,
            'clover_merchant_id' => 'M'.$merchant->id,
            'environment' => 'sandbox',
            'pickup_order_type_id' => 'OT_PICKUP',
            'default_printer_id' => 'PRINTER_1',
            'print_mode' => 'explicit_print',
            'can_create_customers' => true,
            'catalog_synced_at' => now(),
            'sync_max_age_minutes' => 1440,
        ]);

        return CatalogItem::create([
            'clover_connection_id' => $connection->id,
            'clover_item_id' => 'LATTE',
            'name' => 'Latte',
            'price_cents' => 450,
            'tax_rate_bps' => 500,
            'available' => true,
            'modifier_groups' => [
                ['id' => 'size', 'name' => 'Size', 'required' => true, 'options' => [
                    ['id' => 'small', 'name' => 'Small', 'price_cents' => 0],
                    ['id' => 'large', 'name' => 'Large', 'price_cents' => 100],
                ]],
                ['id' => 'milk', 'name' => 'Milk', 'required' => true, 'options' => [
                    ['id' => 'dairy', 'name' => 'Dairy', 'price_cents' => 0],
                    ['id' => 'oat', 'name' => 'Oat', 'price_cents' => 80],
                ]],
            ],
            'synced_at' => now(),
        ]);
    }

    public static function plan(Merchant $merchant, User $owner, string $phone, int $lunch = 1, int $dinner = 1, int $credits = 6): Subscription
    {
        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            'phone_e164' => $phone,
            'name' => 'Jaspreet Kaur',
            'address' => '18 King George Blvd',
        ]);
        $enrollment = app(SubscriptionEnrollment::class);
        $subscription = $enrollment->propose($customer, [
            'plan_kind' => PlanKind::PrepaidPack,
            'service_days' => [1, 2, 3, 4, 5],
            'lunch_portions' => $lunch,
            'dinner_portions' => $dinner,
            'plan_price_cents' => 10000,
            'pack_size' => 10,
            'opening_credits' => $credits,
            'renewal_portion_threshold' => 2,
            'start_date' => '2026-11-23',
            'address' => $customer->address,
            'terms_summary' => 'Weekday meals. First delivery Monday, Nov 23.',
        ]);
        $enrollment->customerConfirm($subscription);
        $approved = $enrollment->ownerApprove(
            $subscription,
            $owner,
            CarbonImmutable::parse('2026-11-23', 'America/Vancouver'),
            CarbonImmutable::parse('2026-11-30', 'America/Vancouver'),
        );
        app(ScheduleGenerator::class)->generate($approved, CarbonImmutable::parse('2026-12-18', 'America/Vancouver'));

        return $approved->fresh();
    }
}
