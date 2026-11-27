@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>Settings</h1>
            <p class="lede">{{ $merchant->name }} · {{ \App\Support\PhoneNumber::display($merchant->whatsappAccount?->display_number) }} · {{ $merchant->timezone }}</p>
        </div>
    </header>
    <form method="post" action="{{ route('settings.update') }}" class="card">
        @csrf
        <fieldset>
            <legend>This location</legend>
            <p>Modules: {{ $merchant->modules->where('enabled', true)->pluck('module')->map(fn ($module) => $module === 'cafe' ? 'Café' : 'Tiffin')->implode(' and ') ?: 'none' }}</p>
            @if ($merchant->cutoff)
                <p class="muted">Next-day cutoff {{ substr((string) $merchant->cutoff->cutoff_local_time, 0, 5) }}, with {{ $merchant->cutoff->grace_minutes }} minutes to confirm a timely request.</p>
            @endif
        </fieldset>
        <fieldset>
            <legend>Reminders</legend>
            <label>Local time
                <input type="time" name="reminder_local_time" value="{{ substr((string) $merchant->reminder_local_time, 0, 5) }}" required>
                <span class="help">Sent on the due date when a balance remains and the customer has not opted out.</span>
            </label>
        </fieldset>
        <fieldset>
            <legend>WhatsApp</legend>
            <p class="muted">The number, phone number id, and Meta token are on <a href="{{ route('whatsapp.edit') }}">WhatsApp</a>.</p>
            <label>Existing WhatsApp Business app number
                <select name="existing_number_status">
                    @foreach (['unchecked' => 'Not checked', 'eligible' => 'Eligible to move', 'ineligible' => 'Keep the current app'] as $status => $label)
                        <option value="{{ $status }}" @selected($merchant->whatsappAccount?->existing_number_status === $status)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Google Business chat
                <select name="google_chat_status">
                    @foreach (['unknown' => 'Not checked', 'unavailable' => 'Chat setting unavailable', 'offered' => 'Offered on the profile'] as $status => $label)
                        <option value="{{ $status }}" @selected($merchant->whatsappAccount?->google_chat_status === $status)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="choice"><input type="checkbox" name="production_access" value="1" @checked($merchant->whatsappAccount?->production_access_confirmed_at)> Production access and permissions confirmed</label>
        </fieldset>
        @if ($merchant->cloverConnection)
            <fieldset>
                <legend>Café</legend>
                <label class="choice"><input type="checkbox" name="ordering_paused" value="1" @checked($merchant->cloverConnection->ordering_paused)> Pause café ordering</label>
                <label class="choice"><input type="checkbox" name="can_create_customers" value="1" @checked($merchant->cloverConnection->can_create_customers)> Allow creating Clover customers</label>
                <label>Kitchen route
                    <select name="print_mode">
                        <option value="explicit_print" @selected($merchant->cloverConnection->print_mode === 'explicit_print')>One explicit print</option>
                        <option value="creation_triggers_print" @selected($merchant->cloverConnection->print_mode === 'creation_triggers_print')>Order creation already prints</option>
                    </select>
                    <span class="help">Only one of these runs automatically. A reprint is always a marked copy.</span>
                </label>
            </fieldset>
        @endif
        <button type="submit">Save settings</button>
    </form>
@endsection
