@extends('layouts.public')
@section('content')
    <div class="public-wrap">
        <section class="hero">
            <div>
                <img class="logo" src="{{ asset('brand/logo.png') }}" alt="Soo Hungry" width="300" height="56">
                <h1>One WhatsApp number for pickup orders and tiffin.</h1>
                <p>A customer writes the merchant. A café gets one unpaid pickup in Clover. A tiffin kitchen gets a confirmed change to tomorrow’s packing list and the customer’s meal balance. A business doing both keeps a single staff inbox.</p>
                <p><a class="button" href="{{ route('login') }}">Staff login</a></p>
            </div>
            <div class="locations">
                <p class="kicker">Locations</p>
                @foreach ($merchants as $merchant)
                    <article class="card loc-card">
                        <h2>{{ $merchant->name }}</h2>
                        <p class="chips">
                            @forelse ($merchant->modules->where('enabled', true) as $module)
                                <span class="pill">{{ $module->module === 'cafe' ? 'Café' : 'Tiffin' }}</span>
                            @empty
                                <span class="pill">No module yet</span>
                            @endforelse
                        </p>
                        <p class="muted">{{ \App\Support\PhoneNumber::display($merchant->whatsappAccount?->display_number) ?: 'No WhatsApp number yet' }} · {{ str_replace('_', ' ', \Illuminate\Support\Str::after($merchant->timezone, '/')) }}</p>
                        @if ($links[$merchant->id])
                            <div class="loc-actions">
                                <a class="button" href="{{ $links[$merchant->id] }}">Message on WhatsApp</a>
                                <a href="{{ route('chat-link', $merchant) }}">Chat page</a>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endsection
