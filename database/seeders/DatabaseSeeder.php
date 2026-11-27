<?php

namespace Database\Seeders;

use App\Domain\Messaging\InboundProcessor;
use App\Domain\Tiffin\ScheduleGenerator;
use App\Domain\Tiffin\SubscriptionEnrollment;
use App\Domain\Billing\BillingService;
use App\Enums\PlanKind;
use App\Enums\StaffRole;
use App\Models\BusinessHour;
use App\Models\CatalogItem;
use App\Models\CloverConnection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CutoffPolicy;
use App\Models\Location;
use App\Models\Merchant;
use App\Models\MerchantModule;
use App\Models\MerchantUser;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->create(['name' => 'Aman Gill', 'email' => 'owner@soohungry.test']);
        $service = User::factory()->create(['name' => 'Surrey Counter', 'email' => 'service@soohungry.test']);
        $kitchen = User::factory()->create(['name' => 'Lets Tiffin Kitchen', 'email' => 'kitchen@soohungry.test']);
        $northOwner = User::factory()->create(['name' => 'North Kitchen', 'email' => 'owner@north.test']);

        $surrey = $this->workspace('Surrey Café', 'surrey-cafe', ['cafe'], 'PN_SURREY', '+16045550100', null);
        $this->membership($surrey['merchant'], $owner, StaffRole::Owner);
        $this->membership($surrey['merchant'], $service, StaffRole::Service);
        $this->catalog($surrey['connection']);

        $tiffin = $this->workspace('Lets Tiffin', 'lets-tiffin', ['tiffin'], 'PN_TIFFIN', '+16045550110', '20:00:00');
        $this->membership($tiffin['merchant'], $owner, StaffRole::Owner);
        $this->membership($tiffin['merchant'], $kitchen, StaffRole::Kitchen);

        $hybrid = $this->workspace('Surrey Kitchen & Café', 'surrey-hybrid', ['cafe', 'tiffin'], 'PN_HYBRID', '+16045550120', '20:00:00');
        $this->membership($hybrid['merchant'], $owner, StaffRole::Owner);
        $this->catalog($hybrid['connection']);

        $north = $this->workspace('North Kitchen', 'north-kitchen', ['tiffin'], 'PN_NORTH', '+16045550140', '22:00:00');
        $this->membership($north['merchant'], $northOwner, StaffRole::Owner);

        $enrollment = app(SubscriptionEnrollment::class);
        $jaspreet = $this->subscriber($tiffin['merchant'], $tiffin['account'], '+16045550123', 'Jaspreet Kaur', '18 King George Blvd, Surrey');
        $this->activate($enrollment, $jaspreet, $owner, 1, 1, 6, '2026-11-25', '2026-11-30', 'Weekday lunch and dinner. First delivery Monday, Nov 23.');
        $manpreet = $this->subscriber($tiffin['merchant'], $tiffin['account'], '+16045550124', 'Manpreet Singh', '90 72 Ave, Surrey');
        $manpreetPlan = $this->activate($enrollment, $manpreet, $owner, 1, 0, 3, '2026-10-01', '2026-10-06', 'Weekday lunch. First delivery Monday, Nov 23.');
        app(BillingService::class)->receive($manpreetPlan->bills()->first(), $owner, 10000, 'e_transfer', '2026-10-01');

        $northCustomer = $this->subscriber($north['merchant'], $north['account'], '+16045550130', 'Other Household', '1 North St');
        $this->activate($enrollment, $northCustomer, $northOwner, 1, 0, 4, '2026-11-01', '2026-11-06', 'Weekday lunch.');

        Customer::create([
            'merchant_id' => $surrey['merchant']->id,
            'phone_e164' => '+16045550199',
            'name' => 'Alex Chen',
            'clover_customer_id' => 'CUS_ALEX',
            'language' => 'en',
        ]);
        $processor = app(InboundProcessor::class);
        $processor->simulate($surrey['merchant'], '+16045550199', 'one latte, large, oat, pickup tomorrow at 12pm', CarbonImmutable::now('America/Vancouver'));
        $processor->simulate($surrey['merchant'], '+16045550199', 'yes', CarbonImmutable::now('America/Vancouver'));
    }

    /**
     * @param  list<string>  $modules
     * @return array{merchant: Merchant, location: Location, account: WhatsAppAccount, connection: ?CloverConnection}
     */
    private function workspace(string $name, string $slug, array $modules, string $phoneNumberId, string $display, ?string $cutoff): array
    {
        $merchant = Merchant::create([
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'America/Vancouver',
            'currency' => 'CAD',
            'reminder_local_time' => '09:00:00',
        ]);
        $location = Location::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'address' => 'Surrey, BC',
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
        if ($cutoff !== null) {
            CutoffPolicy::create([
                'merchant_id' => $merchant->id,
                'cutoff_local_time' => $cutoff,
                'grace_minutes' => 10,
            ]);
        }
        $account = WhatsAppAccount::create([
            'merchant_id' => $merchant->id,
            'location_id' => $location->id,
            'phone_number_id' => $phoneNumberId,
            'display_number' => $display,
            'status' => 'sandbox',
        ]);
        $connection = null;
        if (in_array('cafe', $modules, true)) {
            $connection = CloverConnection::create([
                'merchant_id' => $merchant->id,
                'location_id' => $location->id,
                'clover_merchant_id' => 'CLOVER_'.$slug,
                'environment' => 'sandbox',
                'pickup_order_type_id' => 'OT_PICKUP',
                'default_printer_id' => 'PRINTER_1',
                'print_mode' => 'explicit_print',
                'can_create_customers' => true,
                'catalog_synced_at' => now(),
                'sync_max_age_minutes' => 720,
            ]);
        }

        return compact('merchant', 'location', 'account', 'connection');
    }

    private function membership(Merchant $merchant, User $user, StaffRole $role): void
    {
        MerchantUser::create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function catalog(CloverConnection $connection): void
    {
        CatalogItem::create([
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
        CatalogItem::create([
            'clover_connection_id' => $connection->id,
            'clover_item_id' => 'SANDWICH',
            'name' => 'Breakfast sandwich',
            'price_cents' => 800,
            'tax_rate_bps' => 500,
            'available' => true,
            'modifier_groups' => [],
            'synced_at' => now(),
        ]);
    }

    private function subscriber(Merchant $merchant, WhatsAppAccount $account, string $phone, string $name, string $address): Customer
    {
        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            'phone_e164' => $phone,
            'name' => $name,
            'address' => $address,
            'language' => 'en',
        ]);
        Conversation::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'whatsapp_account_id' => $account->id,
            'status' => 'automated',
        ]);

        return $customer;
    }

    private function activate(
        SubscriptionEnrollment $enrollment,
        Customer $customer,
        User $owner,
        int $lunch,
        int $dinner,
        int $credits,
        string $issued,
        string $due,
        string $terms,
    ): \App\Models\Subscription {
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
            'terms_summary' => $terms,
            'meal_type' => 'regular',
        ]);
        $enrollment->customerConfirm($subscription);
        $approved = $enrollment->ownerApprove(
            $subscription,
            $owner,
            CarbonImmutable::parse($issued, 'America/Vancouver'),
            CarbonImmutable::parse($due, 'America/Vancouver'),
        );
        app(ScheduleGenerator::class)->generate($approved, CarbonImmutable::parse('2026-12-18', 'America/Vancouver'));

        return $approved->fresh();
    }
}
