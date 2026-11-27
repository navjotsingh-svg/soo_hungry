<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\MetaEmbeddedSignup;
use App\Domain\Shared\Auditor;
use App\Models\WhatsAppAccount;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class WhatsAppConnectionController extends StaffController
{
    public function edit(Request $request): View
    {
        $merchant = $this->merchant($request)->load('whatsappAccount.location', 'locations');
        $account = $merchant->whatsappAccount;
        $meta = $this->metaCredentials($account);

        return view('whatsapp.connect', [
            'merchant' => $merchant,
            'account' => $account,
            'webhookUrl' => url('/webhooks/whatsapp'),
            'verifyToken' => (string) config('services.whatsapp.verify_token'),
            'metaReady' => $meta['app_id'] !== '' && $meta['config_id'] !== '' && $meta['app_secret'] !== '',
            'metaAppId' => $meta['app_id'],
            'metaConfigId' => $meta['config_id'],
            'metaSecretOnFile' => ($account?->meta_app_secret ?: config('services.whatsapp.app_secret')) !== '' && ($account?->meta_app_secret ?: config('services.whatsapp.app_secret')) !== null,
            'allowedHost' => $request->getHost(),
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request)->load('whatsappAccount');
        $account = $merchant->whatsappAccount;
        if ($account === null) {
            throw ValidationException::withMessages([
                'meta_app_id' => 'This location has no WhatsApp record yet.',
            ]);
        }

        $data = $request->validate([
            'meta_app_id' => ['required', 'string', 'max:64'],
            'meta_config_id' => ['required', 'string', 'max:64'],
            'meta_app_secret' => ['nullable', 'string', 'max:2000'],
        ]);
        $secret = trim((string) ($data['meta_app_secret'] ?? ''));
        $storedSecret = $account->meta_app_secret ?: (string) config('services.whatsapp.app_secret');
        if ($secret === '' && $storedSecret === '') {
            throw ValidationException::withMessages([
                'meta_app_secret' => 'Paste the Meta app secret once. It is stored encrypted and is not shown again.',
            ]);
        }
        if ($secret !== '' && strlen($secret) < 10) {
            throw ValidationException::withMessages([
                'meta_app_secret' => 'That app secret is too short.',
            ]);
        }

        $payload = [
            'meta_app_id' => trim($data['meta_app_id']),
            'meta_config_id' => trim($data['meta_config_id']),
        ];
        if ($secret !== '') {
            $payload['meta_app_secret'] = $secret;
        }
        $account->update($payload);

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'Meta sign-in is ready. Use Connect with Meta and choose the WhatsApp number.');
    }

    public function meta(Request $request, MetaEmbeddedSignup $signup, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request)->load('whatsappAccount', 'locations');
        $account = $merchant->whatsappAccount;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'waba_id' => ['nullable', 'string', 'max:64'],
        ]);
        $meta = $this->metaCredentials($account);
        if ($meta['app_id'] === '' || $meta['config_id'] === '' || $meta['app_secret'] === '') {
            throw ValidationException::withMessages([
                'meta' => 'Save the Meta app id, configuration id, and app secret first.',
            ]);
        }

        try {
            $connected = $signup->exchange(
                $meta['app_id'],
                $meta['app_secret'],
                $data['code'],
                filled($data['waba_id'] ?? null) ? trim((string) $data['waba_id']) : null,
                filled($data['phone_number_id'] ?? null) ? trim((string) $data['phone_number_id']) : null,
                $account?->display_number,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['meta' => $exception->getMessage()]);
        }

        $this->storeConnection($request, $auditor, $account, $merchant->id, $account?->location_id ?? $merchant->locations()->value('id'), [
            'display_number' => $connected['display_number'],
            'phone_number_id' => $connected['phone_number_id'],
            'waba_id' => $connected['waba_id'] ?? $account?->waba_id,
            'access_token' => $connected['access_token'],
        ]);

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'WhatsApp is connected for this location. Customer messages on this number come into the inbox, and replies go out through Meta.');
    }

    public function start(Request $request, MetaEmbeddedSignup $signup): RedirectResponse
    {
        $merchant = $this->merchant($request)->load('whatsappAccount');
        $account = $merchant->whatsappAccount;
        $data = $request->validate([
            'signup_mode' => ['nullable', 'in:cloud,coexistence'],
            'meta_app_id' => ['nullable', 'string', 'max:64'],
            'meta_config_id' => ['nullable', 'string', 'max:64'],
            'meta_app_secret' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($account !== null && (filled($data['meta_app_id'] ?? null) || filled($data['meta_config_id'] ?? null) || filled($data['meta_app_secret'] ?? null))) {
            $payload = [];
            if (filled($data['meta_app_id'] ?? null)) {
                $payload['meta_app_id'] = trim((string) $data['meta_app_id']);
            }
            if (filled($data['meta_config_id'] ?? null)) {
                $payload['meta_config_id'] = trim((string) $data['meta_config_id']);
            }
            $secret = trim((string) ($data['meta_app_secret'] ?? ''));
            if ($secret !== '') {
                if (strlen($secret) < 10) {
                    throw ValidationException::withMessages(['meta_app_secret' => 'That app secret is too short.']);
                }
                $payload['meta_app_secret'] = $secret;
            }
            if ($payload !== []) {
                $account->update($payload);
                $account->refresh();
            }
        }

        $meta = $this->metaCredentials($account);
        if ($meta['app_id'] === '' || $meta['config_id'] === '' || $meta['app_secret'] === '') {
            throw ValidationException::withMessages([
                'meta' => 'Add the Meta app ID, the Embedded Signup configuration ID, and the app secret. Connect with Meta then opens Meta’s website.',
            ]);
        }

        $state = Str::random(40);
        $request->session()->put('whatsapp_meta_state', $state);

        return redirect()->away($signup->authorizationUrl(
            $meta['app_id'],
            $meta['config_id'],
            route('whatsapp.meta.callback'),
            $state,
            $data['signup_mode'] ?? 'cloud',
        ));
    }

    public function callback(Request $request, MetaEmbeddedSignup $signup, Auditor $auditor): RedirectResponse
    {
        $expected = (string) $request->session()->pull('whatsapp_meta_state');
        $state = (string) $request->query('state', '');
        if ($expected === '' || $state === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('whatsapp.edit')->withErrors([
                'meta' => 'That Meta sign-in could not be matched to this browser. Use Connect with Meta again.',
            ]);
        }
        if ($request->filled('error')) {
            return redirect()->route('whatsapp.edit')->withErrors([
                'meta' => 'Meta did not finish the connection. Use Connect with Meta again.',
            ]);
        }

        $merchant = $this->merchant($request)->load('whatsappAccount', 'locations');
        $account = $merchant->whatsappAccount;
        $meta = $this->metaCredentials($account);

        try {
            $connected = $signup->exchange(
                $meta['app_id'],
                $meta['app_secret'],
                (string) $request->query('code', ''),
                null,
                null,
                $account?->display_number,
            );
        } catch (RuntimeException $exception) {
            return redirect()->route('whatsapp.edit')->withErrors(['meta' => $exception->getMessage()]);
        }

        $this->storeConnection($request, $auditor, $account, $merchant->id, $account?->location_id ?? $merchant->locations()->value('id'), [
            'display_number' => $connected['display_number'],
            'phone_number_id' => $connected['phone_number_id'],
            'waba_id' => $connected['waba_id'] ?? $account?->waba_id,
            'access_token' => $connected['access_token'],
        ]);

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'WhatsApp is connected for this location. Customer messages on this number come into the inbox, and replies go out through Meta.');
    }

    public function update(Request $request, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request)->load('whatsappAccount', 'locations');
        $account = $merchant->whatsappAccount;
        $data = $request->validate([
            'display_number' => ['required', 'string', 'max:32'],
            'phone_number_id' => [
                'required', 'string', 'max:64',
                Rule::unique('whatsapp_accounts', 'phone_number_id')->ignore($account?->id),
            ],
            'waba_id' => ['nullable', 'string', 'max:64'],
        ]);
        $token = trim((string) $request->input('access_token', ''));
        if ($token === '' && $account?->access_token === null) {
            throw ValidationException::withMessages([
                'access_token' => 'Paste the token from Meta to connect this number.',
            ]);
        }
        if ($token !== '' && strlen($token) < 10) {
            throw ValidationException::withMessages([
                'access_token' => 'That token is too short to be a Meta access token.',
            ]);
        }

        $number = PhoneNumber::normalize($data['display_number']);
        if ($number === null) {
            throw ValidationException::withMessages([
                'display_number' => 'Enter the WhatsApp number with the area code.',
            ]);
        }

        $this->storeConnection($request, $auditor, $account, $merchant->id, $account?->location_id ?? $merchant->locations()->value('id'), [
            'display_number' => $number,
            'phone_number_id' => trim($data['phone_number_id']),
            'waba_id' => filled($data['waba_id'] ?? null) ? trim((string) $data['waba_id']) : null,
            'access_token' => $token !== '' ? $token : null,
        ]);

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'WhatsApp is connected for this location. Customer messages on this number come into the inbox, and replies go out through Meta.');
    }

    public function disconnect(Request $request, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request)->load('whatsappAccount');
        $account = $merchant->whatsappAccount;
        if ($account !== null) {
            $account->update([
                'access_token' => null,
                'status' => 'sandbox',
            ]);
            $auditor->record($merchant->id, $request->user(), 'whatsapp.disconnected', $account, 'staff', [
                'phone_number_id' => $account->phone_number_id,
            ]);
        }

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'Live replies are off. Messages that reach the webhook still open in the inbox, and replies stay in the app log.');
    }

    /**
     * @return array{app_id:string, config_id:string, app_secret:string}
     */
    private function metaCredentials(?WhatsAppAccount $account): array
    {
        return [
            'app_id' => (string) ($account?->meta_app_id ?: config('services.whatsapp.app_id')),
            'config_id' => (string) ($account?->meta_config_id ?: config('services.whatsapp.embedded_signup_config_id')),
            'app_secret' => (string) ($account?->meta_app_secret ?: config('services.whatsapp.app_secret')),
        ];
    }

    /**
     * @param  array{display_number:string, phone_number_id:string, waba_id:?string, access_token:?string}  $fields
     */
    private function storeConnection(Request $request, Auditor $auditor, ?WhatsAppAccount $account, int $merchantId, mixed $locationId, array $fields): WhatsAppAccount
    {
        if ($locationId === null) {
            throw ValidationException::withMessages([
                'display_number' => 'Add a location before connecting WhatsApp.',
            ]);
        }

        $taken = WhatsAppAccount::query()
            ->where('phone_number_id', $fields['phone_number_id'])
            ->when($account !== null, fn ($query) => $query->whereKeyNot($account->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'phone_number_id' => 'This phone number id is already connected to a workspace.',
            ]);
        }

        $payload = [
            'merchant_id' => $merchantId,
            'location_id' => $locationId,
            'display_number' => $fields['display_number'],
            'phone_number_id' => $fields['phone_number_id'],
            'waba_id' => $fields['waba_id'],
            'status' => 'connected',
        ];
        if ($fields['access_token'] !== null && $fields['access_token'] !== '') {
            $payload['access_token'] = $fields['access_token'];
        }

        if ($account === null) {
            $account = WhatsAppAccount::create($payload);
        } else {
            $account->update($payload);
        }

        $auditor->record($merchantId, $request->user(), 'whatsapp.connected', $account, 'staff', [
            'phone_number_id' => $account->phone_number_id,
        ]);

        return $account;
    }
}
