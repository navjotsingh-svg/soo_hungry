@extends('layouts.staff')
@section('content')
    <a class="back" href="{{ route('cafe.index') }}">Back to pickup orders</a>
    <header class="page">
        <div>
            <p class="kicker">Pay at pickup</p>
            <h1>{{ $order->pickup_name }}</h1>
            <p class="lede">Reference {{ $order->idempotency_key }} · Clover {{ $order->clover_order_id ?: 'not linked yet' }}</p>
        </div>
    </header>
    <div class="grid">
        <article class="metric"><span>Submission</span><strong class="is-label">{{ \Illuminate\Support\Str::headline($order->submission_status) }}</strong></article>
        <article class="metric"><span>Kitchen</span><strong class="is-label">{{ \Illuminate\Support\Str::headline($order->dispatch_status) }}</strong></article>
        <article class="metric"><span>Pickup</span><strong class="is-label">{{ \Illuminate\Support\Str::headline($order->pickup_status) }}</strong></article>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <form method="post" action="{{ route('cafe.ready', $order) }}">@csrf<button type="submit">Mark ready</button></form>
        <form method="post" action="{{ route('cafe.collected', $order) }}">@csrf<button class="quiet" type="submit">Mark collected</button></form>
        <form method="post" action="{{ route('cafe.reconcile', $order) }}">@csrf<button class="quiet" type="submit">Reconcile Clover</button></form>
        <form method="post" action="{{ route('cafe.reprint', $order) }}">@csrf<button class="quiet" type="submit">Reprint marked copy</button></form>
    </div>
    <div class="work">
        <article class="card">
            <h2>Kitchen ticket</h2>
            <div class="ticket">
                <div class="ref">{{ $order->idempotency_key }}</div>
                <h2>{{ $order->pickup_name }}</h2>
                <p>{{ $order->pickup_at->timezone($currentMerchant->timezone)->format('D M j, g:i A') }}</p>
                <ul>
                    @foreach ($order->lines as $line)
                        <li><strong>{{ $line->quantity }} × {{ $line->name }}</strong>@if ($line->modifiers)<br>{{ collect($line->modifiers)->pluck('name')->implode(', ') }}@endif</li>
                    @endforeach
                </ul>
                <p>{{ \App\Support\Money::cad($order->quoted_total_cents) }} including tax · pay at the counter</p>
            </div>
        </article>
        <article class="card">
            <h2>Print events</h2>
            @forelse ($order->printEvents as $event)
                <p>
                    <span class="pill {{ $event->status === 'printed' ? 'good' : 'warn' }}">{{ \Illuminate\Support\Str::headline($event->kind) }}</span>
                    {{ \Illuminate\Support\Str::headline($event->status) }}
                    @if ($event->marked_copy) · marked copy @endif
                </p>
            @empty
                <p class="empty">No print event recorded. Check the printer before sending another ticket.</p>
            @endforelse
        </article>
    </div>
@endsection
