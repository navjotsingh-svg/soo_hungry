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
<body>
<a class="skip" href="#content">Skip to content</a>
<div id="content">
        @yield('content')
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
