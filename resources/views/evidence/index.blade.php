@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>Pilot log</h1>
            <p class="lede">Ordering attempts, staff interventions, ticket problems, tiffin count changes, and reminder failures for {{ $currentMerchant->name }}. Compare these counts with the café’s phone log. Phone handling time is not stored here.</p>
        </div>
    </header>
    <div class="grid">
        <article class="metric"><span>Pickup orders</span><strong>{{ $orderCount }}</strong></article>
        <article class="metric"><span>Marked reprints</span><strong>{{ $reprints->count() }}</strong></article>
        <article class="metric"><span>Open alerts</span><strong>{{ $alerts->where('status', 'open')->count() }}</strong></article>
        <article class="metric"><span>Chats with staff</span><strong>{{ $openChats }}</strong></article>
    </div>
    <div class="work" style="margin-top:16px">
        <article class="card">
            <h2>Pickup orders</h2>
            <p class="muted">{{ $uncertainOrders }} of the recent orders are still pending or unknown at Clover or the printer.</p>
            @forelse ($orders as $order)
                <p><span class="who">{{ $order->pickup_name }}</span> · {{ $order->submission_status }} · kitchen {{ $order->dispatch_status }}</p>
            @empty
                <p class="empty">No pickup order yet.</p>
            @endforelse
        </article>
        <article class="card">
            <h2>Ticket problems</h2>
            @forelse ($alerts as $alert)
                <p><span class="pill warn">{{ \Illuminate\Support\Str::headline($alert->integration) }}</span> {{ $alert->message }}</p>
            @empty
                <p class="empty">No printer or Clover alert.</p>
            @endforelse
            @foreach ($reprints as $print)
                <p><span class="pill">Reprint</span> {{ $print->order->pickup_name }} · marked copy</p>
            @endforeach
        </article>
    </div>
    <div class="work">
        <article class="card">
            <h2>Staff interventions</h2>
            @forelse ($takeovers as $event)
                <p>Chat taken over {{ $event->created_at?->timezone($currentMerchant->timezone)->format('M j, g:i A') }}</p>
            @empty
                @if ($handoffs->isEmpty())
                    <p class="empty">No staff takeover yet.</p>
                @endif
            @endforelse
            @foreach ($handoffs as $message)
                <p><span class="pill warn">Handoff</span> {{ \Illuminate\Support\Str::limit($message->body, 80) }}</p>
            @endforeach
        </article>
        <article class="card">
            <h2>Tiffin count changes</h2>
            @forelse ($corrections as $event)
                <p>{{ \Illuminate\Support\Str::headline($event->action) }} · {{ $event->created_at?->timezone($currentMerchant->timezone)->format('M j, g:i A') }}</p>
            @empty
                <p class="empty">No skip, pack, or confirmed tiffin change yet.</p>
            @endforelse
        </article>
    </div>
    <article class="card">
        <h2>Reminder failures</h2>
        @forelse ($failedReminders as $reminder)
            <p>
                <span class="who">{{ $reminder->bill->customer->displayName() }}</span>
                · due {{ $reminder->due_on->format('M j') }}
                · {{ $reminder->notification?->failure_reason ?: 'The reminder did not send.' }}
            </p>
        @empty
            <p class="empty">No reminder has failed.</p>
        @endforelse
    </article>
@endsection
