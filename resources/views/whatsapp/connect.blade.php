@extends('layouts.staff')
@php($connected = (bool) $account?->access_token)
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>WhatsApp</h1>
            <p class="lede">{{ $merchant->name }}. Connect the business number with Meta, or keep the WhatsApp Business app on the same number.</p>
        </div>
        <span class="pill {{ $connected ? 'good' : 'warn' }}">{{ $connected ? 'Connected' : 'Not connected' }}</span>
    </header>

    @if ($connected)
        <article class="card">
            <h2>Cloud API</h2>
            <div class="stat-grid">
                <div class="stat-box">
                    <span class="kicker">Phone number ID</span>
                    <strong>{{ $account->phone_number_id }}</strong>
                </div>
                <div class="stat-box">
                    <span class="kicker">Business ID</span>
                    <strong>{{ $account->waba_id ?: '—' }}</strong>
                </div>
                <div class="stat-box wide">
                    <span class="kicker">Connected at</span>
                    <strong>{{ $account->updated_at?->timezone($merchant->timezone)->format('d/m/Y, H:i:s') }}</strong>
                </div>
            </div>
            <p class="banner good">Phone number is registered with Meta (CONNECTED).</p>
            <p class="muted">Customers message {{ \App\Support\PhoneNumber::display($account->display_number) }}. Replies go out through WhatsApp.</p>
        </article>
    @endif

    <article class="card">
        <h2>{{ $connected ? 'Connect another number' : 'Connect a number' }}</h2>
        <form method="post" action="{{ route('whatsapp.meta.start') }}">
            @csrf
            @unless ($metaReady)
                <p>Meta’s website opens from this button. Save the app ID and configuration ID from your Meta app. The app secret is checked when Meta sends you back.</p>
                <label>Meta app ID
                    <input name="meta_app_id" value="{{ old('meta_app_id', $account?->meta_app_id ?: $metaAppId) }}" required>
                </label>
                <label>Embedded Signup configuration ID
                    <input name="meta_config_id" value="{{ old('meta_config_id', $account?->meta_config_id ?: $metaConfigId) }}" required>
                </label>
                <label>App secret
                    <input type="password" name="meta_app_secret" autocomplete="new-password" @unless ($metaSecretOnFile) required @endunless>
                    <span class="help">{{ $metaSecretOnFile ? 'An app secret is already on the server. Paste the secret from the Meta app if that one is not the live secret.' : 'From the Meta app. Stored encrypted and not shown again.' }}</span>
                </label>
            @endunless
            <div class="signup-modes">
                <label class="signup-mode">
                    <input type="radio" name="signup_mode" value="cloud" checked>
                    <span>
                        <strong>Cloud API</strong>
                        <span class="help">API-only number. Registers with a PIN after connect.</span>
                    </span>
                </label>
                <label class="signup-mode">
                    <input type="radio" name="signup_mode" value="coexistence">
                    <span>
                        <strong>Coexistence</strong>
                        <span class="help">Keep the WhatsApp Business app and Cloud API on the same number.</span>
                    </span>
                </label>
            </div>
            <button type="submit" id="meta-connect-button">{{ $connected ? 'Connect another with Meta' : 'Connect with Meta' }}</button>
            <p class="muted">This leaves this page and opens Meta. In the Meta app, add {{ $allowedHost }} under Facebook Login for Business, then Allowed domains, and add {{ route('whatsapp.meta.callback') }} as a valid OAuth redirect URI.</p>
        </form>
        @if ($connected)
            <form method="post" action="{{ route('whatsapp.disconnect') }}">
                @csrf
                <button class="danger" type="submit">Disconnect</button>
            </form>
        @endif
    </article>

    <details class="card" id="advanced">
        <summary>Advanced: Manual connection</summary>
        <form method="post" action="{{ route('whatsapp.setup') }}">
            @csrf
            <fieldset>
                <legend>Meta app</legend>
                <p class="muted">Save these once so Connect with Meta can open. The app secret is not the WhatsApp token.</p>
                <label>Meta app ID
                    <input name="meta_app_id" value="{{ old('meta_app_id', $account?->meta_app_id ?: $metaAppId) }}" required>
                </label>
                <label>Embedded Signup configuration ID
                    <input name="meta_config_id" value="{{ old('meta_config_id', $account?->meta_config_id ?: $metaConfigId) }}" required>
                    <span class="help">In the Meta app, under Facebook Login for Business, create an Embedded Signup configuration.</span>
                </label>
                <label>App secret
                    <input type="password" name="meta_app_secret" autocomplete="new-password">
                    <span class="help">{{ $metaSecretOnFile ? 'An app secret is already saved. Leave this blank to keep it.' : 'Stored encrypted and not shown again.' }}</span>
                </label>
                <button type="submit">Save Meta app</button>
            </fieldset>
        </form>
        <form method="post" action="{{ route('whatsapp.update') }}">
            @csrf
            @method('PUT')
            <fieldset>
                <legend>Paste a token</legend>
                <label>WhatsApp number
                    <input name="display_number" value="{{ old('display_number', \App\Support\PhoneNumber::display($account?->display_number)) }}" placeholder="604 555 0100" required>
                </label>
                <label>Phone number ID
                    <input name="phone_number_id" value="{{ old('phone_number_id', $account?->phone_number_id) }}" required>
                </label>
                <label>WhatsApp Business account ID
                    <input name="waba_id" value="{{ old('waba_id', $account?->waba_id) }}">
                </label>
                <label>Access token
                    <input type="password" name="access_token" autocomplete="new-password" @if (! $connected) required @endif>
                    <span class="help">{{ $connected ? 'A token is saved. Leave this blank to keep it.' : 'Paste the token from Meta. It is stored encrypted and is not shown again.' }}</span>
                </label>
                <button type="submit">Save manual connection</button>
            </fieldset>
        </form>
        <fieldset>
            <legend>Webhook</legend>
            <p><span class="who">Callback URL</span><br><span class="sub">{{ $webhookUrl }}</span></p>
            <p>
                <span class="who">Verify token</span><br>
                <span class="sub">{{ $verifyToken !== '' ? $verifyToken : 'Set WHATSAPP_VERIFY_TOKEN on the server, then paste that same value in Meta.' }}</span>
            </p>
        </fieldset>
    </details>
@endsection
