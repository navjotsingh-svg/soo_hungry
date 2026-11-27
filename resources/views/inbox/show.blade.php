@extends('layouts.staff')
@section('content')
    <a class="back" href="{{ route('inbox.index') }}">Back to inbox</a>
    <header class="page">
        <div>
            <h1>{{ $conversation->customer->displayName() }}</h1>
            <p class="lede">{{ $conversation->customer->phoneDisplay() }}</p>
        </div>
        <div class="page-actions">
            @if ($conversation->status === 'automated')
                <form method="post" action="{{ route('inbox.takeover', $conversation) }}">@csrf<button type="submit">Take over</button></form>
            @else
                <form method="post" action="{{ route('inbox.resume', $conversation) }}">@csrf<button class="quiet" type="submit">Resume automation</button></form>
            @endif
        </div>
    </header>
    @if ($conversation->status === 'staff')
        <p class="banner">Staff has this chat. Automated replies are paused until you resume them.</p>
    @endif
    <div class="work">
        <div>
            <article class="card thread">
                @php
                    $events = collect();
                    foreach ($messages as $message) {
                        $events->push(['at' => $message->wa_timestamp, 'in' => true, 'row' => $message]);
                    }
                    foreach ($outbound as $note) {
                        $events->push(['at' => $note->created_at, 'in' => false, 'row' => $note]);
                    }
                @endphp
                @forelse ($events->sortBy(fn ($event) => $event['at']->getTimestamp())->values() as $event)
                    @if ($event['in'])
                        <div class="bubble">
                            <header>
                                <strong>Customer</strong>
                                <span class="muted">{{ $event['row']->wa_timestamp->timezone($currentMerchant->timezone)->format('M j, g:i A') }}</span>
                            </header>
                            <div>{{ $event['row']->body ?: '['.$event['row']->message_type.']' }}</div>
                            @if ($event['row']->draft)<div class="muted">Read as {{ $event['row']->draft }}</div>@endif
                        </div>
                    @else
                        <div class="bubble out">
                            <header>
                                <strong>{{ $event['row']->source === 'staff' ? 'Staff' : 'Soo Hungry' }}</strong>
                                <span class="pill {{ $event['row']->status === 'failed' ? 'bad' : ($event['row']->status === 'sent' ? 'good' : 'warn') }}">{{ \Illuminate\Support\Str::headline($event['row']->status) }}</span>
                            </header>
                            <div>{{ $event['row']->body }}</div>
                            @if ($event['row']->failure_reason)<div class="error">{{ $event['row']->failure_reason }}</div>@endif
                            @if (in_array($event['row']->status, ['failed', 'blocked'], true))
                                <form method="post" action="{{ route('notifications.retry', $event['row']) }}">@csrf<button type="submit">Retry send</button></form>
                            @endif
                        </div>
                    @endif
                @empty
                    <p class="empty">No messages in this chat yet.</p>
                @endforelse
            </article>
            <form method="post" action="{{ route('inbox.reply', $conversation) }}" class="card composer">
                @csrf
                <h2>Reply</h2>
                <p class="muted">A reply here does not change an order, a skip, or a balance.</p>
                <label>Message<textarea name="body" rows="3" required placeholder="Write to the customer"></textarea></label>
                <div><button type="submit">Send</button></div>
            </form>
        </div>
        <div>
            <article class="card">
                <h2>Waiting on a yes</h2>
                @forelse ($proposals as $proposal)
                    <p><span class="pill {{ $proposal->status->value === 'confirmed' ? 'good' : 'warn' }}">{{ \Illuminate\Support\Str::headline($proposal->status->value) }}</span> {{ \Illuminate\Support\Str::headline($proposal->module->value) }}</p>
                    <p>{{ $proposal->summary }}</p>
                @empty
                    <p class="empty">Nothing is waiting for confirmation.</p>
                @endforelse
            </article>
            <article class="card">
                <h2>Needs a person</h2>
                @forelse ($handoffs as $handoff)
                    <p><span class="pill warn">{{ \Illuminate\Support\Str::headline($handoff->reason) }}</span></p>
                    <p>{{ $handoff->draft_interpretation }}</p>
                @empty
                    <p class="empty">No handoffs on this chat.</p>
                @endforelse
            </article>
            <details class="card">
                <summary>Send the next customer message</summary>
                <form method="post" action="{{ route('inbox.simulate', $conversation) }}">
                    @csrf
                    <label>When<input type="datetime-local" name="sent_at"></label>
                    <label>Message<textarea name="body" rows="3" required></textarea></label>
                    <button type="submit">Send as customer</button>
                </form>
            </details>
        </div>
    </div>
@endsection
