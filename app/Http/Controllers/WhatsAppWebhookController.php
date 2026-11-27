<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\InboundProcessor;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge'));
        $expected = (string) config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200);
        }

        abort(403);
    }

    public function receive(Request $request, InboundProcessor $processor): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        if ($secret === '') {
            abort(503, 'WhatsApp app secret is not configured.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $given = (string) $request->header('X-Hub-Signature-256');
        if (strlen($given) !== strlen($expected) || ! hash_equals($expected, $given)) {
            abort(401);
        }

        $payload = $request->json()->all();
        $event = WebhookEvent::create([
            'payload_hash' => hash('sha256', $request->getContent()),
            'phone_number_id' => data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id'),
            'raw_payload' => $payload,
            'signature_valid' => true,
            'created_at' => now(),
        ]);
        $processor->receiveWebhook($payload, $event->id);

        return response('OK');
    }
}
