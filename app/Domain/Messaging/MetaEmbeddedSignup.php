<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaEmbeddedSignup
{
    /**
     * @return array{access_token:string, phone_number_id:string, display_number:string, waba_id:?string}
     */
    public function exchange(string $appId, string $appSecret, string $code, ?string $wabaId, ?string $phoneNumberId, ?string $fallbackDisplay): array
    {
        $version = (string) config('services.whatsapp.graph_version', 'v21.0');
        $tokenResponse = Http::asForm()->timeout(15)->post("https://graph.facebook.com/{$version}/oauth/access_token", [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code,
        ]);
        $token = $tokenResponse->json('access_token');
        if ($tokenResponse->failed() || ! is_string($token) || $token === '') {
            throw new RuntimeException($this->reason($tokenResponse) ?? 'Meta did not return a token. Try Connect with Meta again.');
        }

        if (($wabaId === null || $wabaId === '') && ($phoneNumberId === null || $phoneNumberId === '')) {
            $wabaId = $this->wabaFromToken($version, $appId, $appSecret, $token);
        }

        $number = $this->phone($version, $token, $wabaId, $phoneNumberId);
        $display = PhoneNumber::normalize($number['display'] ?? $fallbackDisplay);
        if ($display === null || $number['id'] === '') {
            throw new RuntimeException('Meta signed in, but did not return a WhatsApp number. Add the number in the popup, then try again.');
        }

        return [
            'access_token' => $token,
            'phone_number_id' => $number['id'],
            'display_number' => $display,
            'waba_id' => $wabaId !== null && $wabaId !== '' ? $wabaId : null,
        ];
    }

    /**
     * @return array{id:string, display:?string}
     */
    private function phone(string $version, string $token, ?string $wabaId, ?string $phoneNumberId): array
    {
        if ($phoneNumberId !== null && $phoneNumberId !== '') {
            $one = Http::withToken($token)->timeout(15)->get("https://graph.facebook.com/{$version}/{$phoneNumberId}", [
                'fields' => 'display_phone_number',
            ]);
            if ($one->successful()) {
                return [
                    'id' => $phoneNumberId,
                    'display' => is_string($one->json('display_phone_number')) ? $one->json('display_phone_number') : null,
                ];
            }
        }

        if ($wabaId === null || $wabaId === '') {
            throw new RuntimeException('Meta signed in, but did not return a WhatsApp number. Choose the number on Meta’s page, then try again.');
        }

        $list = Http::withToken($token)->timeout(15)->get("https://graph.facebook.com/{$version}/{$wabaId}/phone_numbers");
        if ($list->failed()) {
            throw new RuntimeException($this->reason($list) ?? 'Meta did not return the WhatsApp numbers for this business.');
        }

        $numbers = $list->json('data');
        if (! is_array($numbers) || $numbers === []) {
            throw new RuntimeException('Meta signed in, but this business has no WhatsApp number yet.');
        }

        $chosen = $numbers[0];
        foreach ($numbers as $number) {
            if (($number['id'] ?? null) === $phoneNumberId) {
                $chosen = $number;
                break;
            }
        }

        return [
            'id' => (string) ($chosen['id'] ?? ''),
            'display' => is_string($chosen['display_phone_number'] ?? null) ? $chosen['display_phone_number'] : null,
        ];
    }

    public function authorizationUrl(string $appId, string $configId, string $redirectUri, string $state, string $mode): string
    {
        $version = (string) config('services.whatsapp.graph_version', 'v21.0');
        $query = http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'config_id' => $configId,
            'override_default_response_type' => 'true',
            'display' => 'page',
            'extras' => json_encode([
                'setup' => new \stdClass(),
                'featureType' => $mode === 'coexistence' ? 'whatsapp_business_app_onboarding' : '',
                'sessionInfoVersion' => '3',
            ], JSON_THROW_ON_ERROR),
        ]);

        return "https://www.facebook.com/{$version}/dialog/oauth?{$query}";
    }

    private function wabaFromToken(string $version, string $appId, string $appSecret, string $token): ?string
    {
        $debug = Http::timeout(15)->get("https://graph.facebook.com/{$version}/debug_token", [
            'input_token' => $token,
            'access_token' => $appId.'|'.$appSecret,
        ]);
        $scopes = $debug->json('data.granular_scopes');
        if (! is_array($scopes)) {
            return null;
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                continue;
            }
            $ids = $scope['target_ids'] ?? [];
            if (is_array($ids) && isset($ids[0]) && $ids[0] !== '') {
                return (string) $ids[0];
            }
        }

        return null;
    }

    private function reason(Response $response): ?string
    {
        $message = $response->json('error.message');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
