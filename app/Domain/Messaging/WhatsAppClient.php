<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\WhatsAppAccount;

interface WhatsAppClient
{
    public function sendText(?WhatsAppAccount $account, string $to, string $body): string;

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $body): string;
}
