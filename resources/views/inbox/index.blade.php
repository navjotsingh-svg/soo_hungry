@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Service</p>
            <h1>Inbox</h1>
            <p class="lede">Customer messages for {{ $currentMerchant->name }}. Take over a chat when the request is unclear.</p>
        </div>
    </header>
    <article class="card">
        @forelse ($conversations as $conversation)
            <a class="list-row" href="{{ route('inbox.show', $conversation) }}">
                <span>
                    <span class="who">{{ $conversation->customer->displayName() }}</span>
                    <span class="sub">{{ $conversation->customer->phoneDisplay() }}</span>
                    @if ($conversation->latestMessage?->body)
                        <span class="preview">{{ \Illuminate\Support\Str::limit($conversation->latestMessage->body, 88) }}</span>
                    @endif
                </span>
                <span class="list-end">
                    @if ($conversation->messages_max_wa_timestamp)
                        <span class="sub">{{ \Carbon\CarbonImmutable::parse($conversation->messages_max_wa_timestamp)->timezone($currentMerchant->timezone)->format('M j, g:i A') }}</span>
                    @endif
                    <span class="pill {{ $conversation->status === 'staff' ? 'warn' : 'good' }}">{{ $conversation->status === 'staff' ? 'With staff' : 'Automated' }}</span>
                </span>
            </a>
        @empty
            <p class="empty">No conversations yet. A new WhatsApp message will open one here.</p>
        @endforelse
    </article>
    <details class="card">
        <summary>Send a test message</summary>
        <p class="muted">Uses the same path as the WhatsApp webhook. Times are in {{ $currentMerchant->timezone }}.</p>
        <form method="post" action="{{ route('inbox.start') }}">
            @csrf
            <div class="row">
                <label>Phone<input name="phone" placeholder="+16045550123" required></label>
                <label>When<input type="datetime-local" name="sent_at"></label>
            </div>
            <label>Message<textarea name="body" rows="3" required></textarea></label>
            <button type="submit">Send as customer</button>
        </form>
    </details>
@endsection
