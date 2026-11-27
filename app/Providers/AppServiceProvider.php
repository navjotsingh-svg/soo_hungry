<?php

namespace App\Providers;

use App\Domain\Cafe\CloverGateway;
use App\Domain\Cafe\HttpCloverGateway;
use App\Domain\Cafe\SimulatedCloverGateway;
use App\Domain\Messaging\HttpWhatsAppClient;
use App\Domain\Messaging\LogWhatsAppClient;
use App\Domain\Messaging\WhatsAppClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SimulatedCloverGateway::class);
        $this->app->bind(CloverGateway::class, function ($app): CloverGateway {
            if (config('services.clover.driver') === 'http') {
                return new HttpCloverGateway((string) config('services.clover.base_url'));
            }

            return $app->make(SimulatedCloverGateway::class);
        });
        $this->app->bind(WhatsAppClient::class, function ($app): WhatsAppClient {
            return config('services.whatsapp.driver') === 'http'
                ? $app->make(HttpWhatsAppClient::class)
                : $app->make(LogWhatsAppClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
