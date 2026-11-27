<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CafeOrderController;
use App\Http\Controllers\ChatLinkController;
use App\Http\Controllers\CustomerDirectoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MerchantSwitchController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PilotEvidenceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffDirectoryController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\TiffinMenuController;
use App\Http\Controllers\WhatsAppConnectionController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);
Route::get('/m/{merchant:slug}', ChatLinkController::class)->name('chat-link');

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'merchant'])->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/merchant', MerchantSwitchController::class)->name('merchant.switch');
    Route::get('/today', DashboardController::class)->name('dashboard');
    Route::get('/password', [AccountPasswordController::class, 'edit'])->name('account.password');
    Route::put('/password', [AccountPasswordController::class, 'update'])->name('account.password.update');

    Route::middleware('role:owner,service')->group(function (): void {
        Route::get('/customers', [CustomerDirectoryController::class, 'index'])->name('customers.index');
        Route::get('/customers/new', [CustomerDirectoryController::class, 'create'])->name('customers.create');
        Route::post('/customers/new', [CustomerDirectoryController::class, 'store']);
        Route::post('/customers', [CustomerDirectoryController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerDirectoryController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerDirectoryController::class, 'update'])->name('customers.update');

        Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');
        Route::get('/menu/new', [MenuController::class, 'create'])->name('menu.create');
        Route::post('/menu', [MenuController::class, 'store'])->name('menu.store');
        Route::get('/menu/{catalogItem}/edit', [MenuController::class, 'edit'])->name('menu.edit');
        Route::put('/menu/{catalogItem}', [MenuController::class, 'update'])->name('menu.update');

        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('/inbox', [InboxController::class, 'start'])->name('inbox.start');
        Route::get('/inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        Route::post('/inbox/{conversation}/simulate', [InboxController::class, 'simulate'])->name('inbox.simulate');
        Route::post('/inbox/{conversation}/takeover', [InboxController::class, 'takeover'])->name('inbox.takeover');
        Route::post('/inbox/{conversation}/resume', [InboxController::class, 'resume'])->name('inbox.resume');
        Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
        Route::post('/notifications/{notification}/retry', [InboxController::class, 'retry'])->name('notifications.retry');
    });

    Route::middleware('role:owner,kitchen,service')->group(function (): void {
        Route::get('/tiffin-menu', [TiffinMenuController::class, 'index'])->name('tiffin-menu.index');
        Route::post('/tiffin-menu', [TiffinMenuController::class, 'store'])->name('tiffin-menu.store');
        Route::get('/tiffin-menu/{tiffinMenuItem}/edit', [TiffinMenuController::class, 'edit'])->name('tiffin-menu.edit');
        Route::put('/tiffin-menu/{tiffinMenuItem}', [TiffinMenuController::class, 'update'])->name('tiffin-menu.update');
        Route::delete('/tiffin-menu/{tiffinMenuItem}', [TiffinMenuController::class, 'destroy'])->name('tiffin-menu.destroy');
    });

    Route::middleware('role:owner,kitchen')->group(function (): void {
        Route::get('/packing', [PackingController::class, 'index'])->name('packing.index');
        Route::post('/packing/{entry}/fulfill', [PackingController::class, 'fulfill'])->name('packing.fulfill');
        Route::post('/packing/{entry}/skip', [PackingController::class, 'skip'])->name('packing.skip');
        Route::post('/packing/customers/{customer}/assignment', [PackingController::class, 'assign'])->name('packing.assign');
        Route::post('/actions/{action}/approve-late', [PackingController::class, 'approveLate'])->name('actions.approve-late');
    });

    Route::middleware('role:owner')->group(function (): void {
        Route::get('/subscribers', [SubscriberController::class, 'index'])->name('subscribers.index');
        Route::post('/subscribers/{subscription}/approve', [SubscriberController::class, 'approve'])->name('subscribers.approve');
        Route::get('/dues', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/dues/{bill}/payments', [BillingController::class, 'payment'])->name('billing.payment');
        Route::post('/renewals/{prompt}/approve', [BillingController::class, 'approveRenewal'])->name('renewals.approve');
        Route::get('/evidence', [PilotEvidenceController::class, 'index'])->name('evidence.index');
        Route::get('/whatsapp', [WhatsAppConnectionController::class, 'edit'])->name('whatsapp.edit');
        Route::post('/whatsapp/setup', [WhatsAppConnectionController::class, 'setup'])->name('whatsapp.setup');
        Route::post('/whatsapp/meta', [WhatsAppConnectionController::class, 'meta'])->name('whatsapp.meta');
        Route::post('/whatsapp/meta/start', [WhatsAppConnectionController::class, 'start'])->name('whatsapp.meta.start');
        Route::get('/whatsapp/meta/callback', [WhatsAppConnectionController::class, 'callback'])->name('whatsapp.meta.callback');
        Route::put('/whatsapp', [WhatsAppConnectionController::class, 'update'])->name('whatsapp.update');
        Route::post('/whatsapp/disconnect', [WhatsAppConnectionController::class, 'disconnect'])->name('whatsapp.disconnect');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/staff', [StaffDirectoryController::class, 'index'])->name('staff.index');
        Route::get('/staff/new', [StaffDirectoryController::class, 'create'])->name('staff.create');
        Route::post('/staff', [StaffDirectoryController::class, 'store'])->name('staff.store');
        Route::get('/staff/{user}/edit', [StaffDirectoryController::class, 'edit'])->name('staff.edit');
        Route::put('/staff/{user}', [StaffDirectoryController::class, 'update'])->name('staff.update');
        Route::put('/staff/{user}/password', [StaffDirectoryController::class, 'password'])->name('staff.password');
    });

    Route::middleware('role:owner,service')->group(function (): void {
        Route::get('/pickup', [CafeOrderController::class, 'index'])->name('cafe.index');
        Route::get('/pickup/{order}', [CafeOrderController::class, 'show'])->name('cafe.show');
        Route::post('/pickup/{order}/ready', [CafeOrderController::class, 'ready'])->name('cafe.ready');
        Route::post('/pickup/{order}/collected', [CafeOrderController::class, 'collected'])->name('cafe.collected');
        Route::post('/pickup/{order}/reprint', [CafeOrderController::class, 'reprint'])->name('cafe.reprint');
        Route::post('/pickup/{order}/reconcile', [CafeOrderController::class, 'reconcile'])->name('cafe.reconcile');
        Route::post('/pickup/items/{item}/availability', [CafeOrderController::class, 'availability'])->name('cafe.availability');
        Route::post('/pickup/sync', [CafeOrderController::class, 'sync'])->name('cafe.sync');
    });
});
