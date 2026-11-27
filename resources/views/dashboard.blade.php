@extends('layouts.staff')
@section('content')
    @php($today = \Carbon\CarbonImmutable::now($merchant->timezone))
    <header class="page">
        <div>
            <p class="kicker">{{ $today->format('l') }}</p>
            <h1>{{ $today->format('F j') }}</h1>
            <p class="lede">{{ $merchant->name }} · {{ $merchant->timezone }} · {{ strtoupper($merchant->currency) }}</p>
        </div>
    </header>
    <div class="grid">
        @if (in_array($membership->role->value, ['owner', 'service'], true))
            <a class="metric" href="{{ route('inbox.index') }}"><span>Waiting on staff</span><strong>{{ $handoffs }}</strong></a>
            <a class="metric" href="{{ route('inbox.index') }}"><span>Failed sends</span><strong>{{ $failedSends }}</strong></a>
        @else
            <article class="metric"><span>Waiting on staff</span><strong>{{ $handoffs }}</strong></article>
            <article class="metric"><span>Failed sends</span><strong>{{ $failedSends }}</strong></article>
        @endif
        @if ($tomorrowPortions !== null && in_array($membership->role->value, ['owner', 'kitchen'], true))
            <a class="metric" href="{{ route('packing.index', ['date' => $tomorrow]) }}"><span>{{ \Carbon\CarbonImmutable::parse($tomorrow)->format('l') }} portions</span><strong>{{ $tomorrowPortions }}</strong></a>
        @elseif ($tomorrowPortions !== null)
            <article class="metric"><span>{{ \Carbon\CarbonImmutable::parse($tomorrow)->format('l') }} portions</span><strong>{{ $tomorrowPortions }}</strong></article>
        @endif
        @if ($outstanding !== null)
            <a class="metric" href="{{ route('billing.index') }}"><span>Tiffin balance</span><strong>{{ \App\Support\Money::cad($outstanding) }}</strong></a>
        @endif
        @if ($openOrders !== null)
            <a class="metric" href="{{ route('cafe.index') }}"><span>Open pickups</span><strong>{{ $openOrders }}</strong></a>
        @endif
    </div>
    <article class="card" style="margin-top:16px">
        <h2>Needs a look</h2>
        @forelse ($alerts as $alert)
            <p><span class="pill warn">{{ \Illuminate\Support\Str::headline($alert->integration) }}</span> {{ $alert->message }}</p>
        @empty
            <p class="empty">Nothing is waiting. Failed sends, printer checks, and late changes show up here.</p>
        @endforelse
    </article>
@endsection
