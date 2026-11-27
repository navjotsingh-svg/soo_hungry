<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Soo Hungry' }}</title>
    <link rel="icon" href="{{ asset('brand/icon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('brand/icon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/staff.css') }}?v={{ filemtime(public_path('css/staff.css')) }}">
</head>
<body class="app-dark">
<a class="skip" href="#content">Skip to content</a>
<div class="shell">
    <aside class="side">
        <a class="logo-plate" href="{{ route('dashboard') }}">
            <img src="{{ asset('brand/logo.png') }}" alt="Soo Hungry" width="300" height="56">
        </a>
        <p class="workspace-name">{{ $currentMerchant->name }}</p>
        <p class="workspace-role">{{ $membership->role->label() }}</p>
        @if ($navMerchants->count() > 1)
            <form class="switch" method="post" action="{{ route('merchant.switch') }}">
                @csrf
                <label>
                    <span>Workspace</span>
                    <select name="merchant_id" aria-label="Workspace" onchange="this.form.requestSubmit()">
                        @foreach ($navMerchants as $nav)
                            <option value="{{ $nav->merchant_id }}" @selected($nav->merchant_id === $currentMerchant->id)>{{ $nav->merchant->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="quiet" type="submit">Switch</button>
            </form>
        @endif
        <nav class="nav" aria-label="Workspace">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Today</a>
            @if (in_array($membership->role->value, ['owner', 'service'], true))
                <p class="nav-label">Records</p>
                <a class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}">Customers</a>
                @if ($currentMerchant->hasModule('cafe'))
                    <a class="nav-link {{ request()->routeIs('menu.*') ? 'active' : '' }}" href="{{ route('menu.index') }}">{{ $currentMerchant->hasModule('tiffin') ? 'Café menu' : 'Menu' }}</a>
                @endif
                @if ($currentMerchant->hasModule('tiffin'))
                    <a class="nav-link {{ request()->routeIs('tiffin-menu.*') ? 'active' : '' }}" href="{{ route('tiffin-menu.index') }}">{{ $currentMerchant->hasModule('cafe') ? 'Tiffin menu' : 'Menu' }}</a>
                @endif
                <p class="nav-label">Service</p>
                <a class="nav-link {{ request()->routeIs('inbox.*') ? 'active' : '' }}" href="{{ route('inbox.index') }}">Inbox</a>
                @if ($currentMerchant->hasModule('cafe'))
                    <a class="nav-link {{ request()->routeIs('cafe.*') ? 'active' : '' }}" href="{{ route('cafe.index') }}">Pickup orders</a>
                @endif
            @endif
            @if ($currentMerchant->hasModule('tiffin') && in_array($membership->role->value, ['owner', 'kitchen'], true))
                <p class="nav-label">Kitchen</p>
                <a class="nav-link {{ request()->routeIs('packing.*') ? 'active' : '' }}" href="{{ route('packing.index') }}">Packing</a>
                @if ($membership->role->value === 'kitchen')
                    <a class="nav-link {{ request()->routeIs('tiffin-menu.*') ? 'active' : '' }}" href="{{ route('tiffin-menu.index') }}">Menu</a>
                @endif
            @endif
            @if ($membership->role->seesFinancials() && $currentMerchant->hasModule('tiffin'))
                <p class="nav-label">Money</p>
                <a class="nav-link {{ request()->routeIs('subscribers.*') ? 'active' : '' }}" href="{{ route('subscribers.index') }}">Subscribers</a>
                <a class="nav-link {{ request()->routeIs('billing.*') ? 'active' : '' }}" href="{{ route('billing.index') }}">Dues</a>
            @endif
            <p class="nav-label">Account</p>
            @if ($membership->role->changesCredentials())
                <a class="nav-link {{ request()->routeIs('staff.*') ? 'active' : '' }}" href="{{ route('staff.index') }}">Staff</a>
                <a class="nav-link {{ request()->routeIs('evidence.*') ? 'active' : '' }}" href="{{ route('evidence.index') }}">Pilot log</a>
                <a class="nav-link {{ request()->routeIs('whatsapp.*') ? 'active' : '' }}" href="{{ route('whatsapp.edit') }}">WhatsApp</a>
                <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.edit') }}">Settings</a>
            @endif
            <a class="nav-link {{ request()->routeIs('account.password') ? 'active' : '' }}" href="{{ route('account.password') }}">Password</a>
        </nav>
        <form class="logout" method="post" action="{{ route('logout') }}">
            @csrf
            <button class="quiet" type="submit">Log out</button>
        </form>
    </aside>
    <main class="main" id="content">
        @if (session('status'))
            <p class="banner good" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="banner bad" role="alert">{{ $errors->first() }}</p>
        @endif
        @yield('content')
    </main>
</div>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (registrations) {
            registrations.forEach(function (registration) { registration.unregister(); });
        });
    }
</script>
</body>
</html>
