@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Service</p>
            <h1>Pickup orders</h1>
            <p class="lede">Customers pay at the counter. These totals stay out of tiffin collections.</p>
        </div>
        <form method="post" action="{{ route('cafe.sync') }}">@csrf<button class="quiet" type="submit">Mark menu synced</button></form>
    </header>
    <div class="work">
        <article class="card">
            <h2>Orders</h2>
            @forelse ($orders as $order)
                <a class="list-row" href="{{ route('cafe.show', $order) }}">
                    <span>
                        <span class="who">{{ $order->pickup_name }}</span>
                        <span class="sub">{{ $order->pickup_at->timezone($currentMerchant->timezone)->format('D g:i A') }} · {{ \Illuminate\Support\Str::headline($order->submission_status) }}</span>
                    </span>
                    <span class="list-end">
                        <strong>{{ \App\Support\Money::cad($order->quoted_total_cents) }}</strong>
                        <span class="pill {{ $order->pickup_status === 'collected' ? 'good' : 'warn' }}">{{ \Illuminate\Support\Str::headline($order->pickup_status) }}</span>
                    </span>
                </a>
            @empty
                <p class="empty">No pickup orders yet. A confirmed WhatsApp order appears here once.</p>
            @endforelse
        </article>
        <article class="card">
            <h2>Menu <a class="back" href="{{ route('menu.index') }}">Maintain</a></h2>
            @foreach ($items as $item)
                <div class="menu-row">
                    <span>
                        <span class="who">{{ $item->name }}</span>
                        <span class="sub">{{ \App\Support\Money::cad($item->price_cents) }} before tax</span>
                    </span>
                    <form method="post" action="{{ route('cafe.availability', $item) }}">
                        @csrf
                        <button class="quiet" type="submit">{{ $item->available ? 'Available' : 'Sold out' }}</button>
                    </form>
                </div>
            @endforeach
        </article>
    </div>
@endsection
