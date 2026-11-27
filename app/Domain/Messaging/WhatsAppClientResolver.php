<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\WhatsAppAccount;

final class WhatsAppClientResolver
{
    public function __construct(
        private readonly LogWhatsAppClient $log,
        private readonly HttpWhatsAppClient $http,
    ) {}

    public function forAccount(?WhatsAppAccount $account): WhatsAppClient
    {
        if (config('services.whatsapp.driver') === 'http' || filled($account?->access_token)) {
            return $this->http;
        }

        return $this->log;
    }
}
