@extends('layouts.public')
@section('content')
    <div class="gate">
        <section class="gate-brand">
            <img class="logo" src="{{ asset('brand/logo.png') }}" alt="Soo Hungry" width="300" height="56">
            <h1>The counter and the kitchen, one inbox.</h1>
            <p>WhatsApp orders, packing changes, and dues for this location.</p>
        </section>
        <section class="gate-panel">
            <h1>Staff login</h1>
            <form method="post" action="{{ route('login') }}">
                @csrf
                <label>Email
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </label>
                <label>Password
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                @error('email')<p class="error">{{ $message }}</p>@enderror
                <button type="submit">Sign in</button>
            </form>
            @if (app()->environment('local'))
                <article class="card" style="margin-top:24px">
                    <h2>Pilot accounts</h2>
                    <ul class="accounts">
                        <li><span>Owner, café and tiffin</span><code>owner@soohungry.test</code></li>
                        <li><span>Surrey café service</span><code>service@soohungry.test</code></li>
                        <li><span>Lets Tiffin kitchen</span><code>kitchen@soohungry.test</code></li>
                    </ul>
                    <p class="muted">Password for each is <code>password</code>.</p>
                </article>
            @endif
        </section>
    </div>
@endsection
