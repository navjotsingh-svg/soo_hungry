<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\WhatsAppAccount;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class LogWhatsAppClient implements WhatsAppClient
{
    public function sendText(?WhatsAppAccount $account, string $to, string $body): string
    {
        return $this->deliver($to, $body, null);
    }

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $body): string
    {
        return $this->deliver($to, $body, $template);
    }

    private function deliver(string $to, string $body, ?string $template): string
    {
        if (config('services.whatsapp.fail')) {
            throw new RuntimeException('WhatsApp gateway failed');
        }

        Log::info('whatsapp.outbound', [
            'to' => PhoneNumber::mask($to),
            'template' => $template,
            'chars' => mb_strlen($body),
        ]);

        return 'wamid.'.Str::uuid();
    }
}
