<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWhatsAppClient implements WhatsAppClient
{
    public function sendText(?WhatsAppAccount $account, string $to, string $body): string
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $body): string
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'en'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $body]],
                ]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(?WhatsAppAccount $account, array $payload): string
    {
        if ($account === null || $account->access_token === null) {
            throw new RuntimeException('WhatsApp access token is not configured.');
        }

        $version = config('services.whatsapp.graph_version', 'v21.0');
        $response = Http::withToken($account->access_token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages", $payload);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp send failed.');
        }

        return (string) $response->json('messages.0.id');
    }
}
