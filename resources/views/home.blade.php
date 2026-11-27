@extends('layouts.public')
@section('content')
    <div class="home">
        <section class="home-hero">
            <div class="home-hero-inner">
                <nav class="home-nav" aria-label="Soo Hungry">
                    <img class="logo" src="{{ asset('brand/logo.png') }}" alt="Soo Hungry" width="300" height="56">
                    <a class="home-login" href="{{ route('login') }}">Staff login</a>
                </nav>
                <div class="home-hero-grid">
                    <div>
                        <p class="kicker">WhatsApp for the counter and the kitchen</p>
                        <h1>One number for pickup orders and tiffin.</h1>
                        <p class="lede">A customer writes the merchant. A café gets one unpaid pickup in Clover. A tiffin kitchen gets a confirmed change on the packing list. A business doing both keeps a single staff inbox.</p>
                        <div class="home-actions">
                            <a class="button" href="#locations">Choose a location</a>
                            <a class="button quiet" href="{{ route('login') }}">Staff login</a>
                        </div>
                    </div>
                    <aside class="home-preview" aria-label="How a message becomes an order">
                        <p class="home-preview-label">Café pickup</p>
                        <div class="bubble out">One chai and a breakfast sandwich. Pickup at noon.</div>
                        <div class="bubble in">Total includes tax. Pay at the counter. Reply YES to send this to the café.</div>
                        <div class="mini-ticket">
                            <span>Kitchen ticket</span>
                            <strong>Noon pickup</strong>
                            <ul>
                                <li>1 × Chai</li>
                                <li>1 × Breakfast sandwich</li>
                            </ul>
                            <em>One print to the Clover kitchen</em>
                        </div>
                    </aside>
                </div>
            </div>
        </section>

        <div class="home-body">
            <section class="home-steps" aria-label="How it works">
                <article class="card home-step">
                    <span>01</span>
                    <h2>They write</h2>
                    <p>A dish and a pickup time, or tomorrow’s breakfast, lunch, and dinner.</p>
                </article>
                <article class="card home-step">
                    <span>02</span>
                    <h2>They confirm</h2>
                    <p>One YES sends one café order, or one change to the tiffin schedule.</p>
                </article>
                <article class="card home-step">
                    <span>03</span>
                    <h2>The kitchen gets it</h2>
                    <p>Café tickets print through Clover. Tiffin meals land on the packing list.</p>
                </article>
            </section>

            <section id="locations" class="home-places">
                <header class="home-places-head">
                    <div>
                        <p class="kicker">Locations</p>
                        <h2>Write the kitchen on WhatsApp</h2>
                    </div>
                    <img src="{{ asset('brand/icon.png') }}" alt="" width="72" height="72">
                </header>
                @if ($merchants->isEmpty())
                    <p class="empty">No locations are open yet.</p>
                @else
                    <div class="home-grid">
                        @foreach ($merchants as $merchant)
                            @php($modules = $merchant->modules->where('enabled', true))
                            <article class="card place">
                                <div class="place-top">
                                    <img src="{{ asset('brand/icon.png') }}" alt="" width="48" height="48">
                                    <div>
                                        <h2>{{ $merchant->name }}</h2>
                                        <p class="chips">
                                            @forelse ($modules as $module)
                                                <span class="pill {{ $module->module === 'cafe' ? '' : 'good' }}">{{ $module->module === 'cafe' ? 'Café' : 'Tiffin' }}</span>
                                            @empty
                                                <span class="pill">No module yet</span>
                                            @endforelse
                                        </p>
                                    </div>
                                </div>
                                <p class="place-meta">{{ \App\Support\PhoneNumber::display($merchant->whatsappAccount?->display_number) ?: 'No WhatsApp number yet' }}</p>
                                <p class="muted">{{ str_replace('_', ' ', \Illuminate\Support\Str::after($merchant->timezone, '/')) }}</p>
                                @if ($links[$merchant->id])
                                    <div class="loc-actions">
                                        <a class="button" href="{{ $links[$merchant->id] }}">Message {{ $merchant->name }}</a>
                                        <a href="{{ route('chat-link', $merchant) }}">Chat page</a>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <footer class="home-foot">
                <img src="{{ asset('brand/icon.png') }}" alt="" width="36" height="36">
                <p>Soo Hungry</p>
                <a href="{{ route('login') }}">Staff login</a>
            </footer>
        </div>
    </div>
@endsection
