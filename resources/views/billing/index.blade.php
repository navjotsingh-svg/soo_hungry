@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Money</p>
            <h1>Dues</h1>
            <p class="lede">A closed month stays as of its last day. This month runs through {{ \Carbon\CarbonImmutable::parse($report->asOf)->format('F j') }} in {{ $currentMerchant->timezone }}. Pickup tickets are not cash received.</p>
        </div>
        <form class="page-actions" method="get">
            <label>Month<input type="month" name="month" value="{{ $month }}"></label>
            <button class="quiet" type="submit">Show</button>
        </form>
    </header>
    <div class="grid">
        <article class="metric"><span>Billed</span><strong>{{ \App\Support\Money::cad($report->billedCents) }}</strong></article>
        <article class="metric"><span>Collected</span><strong>{{ \App\Support\Money::cad($report->collectedCents) }}</strong></article>
        <article class="metric"><span>Outstanding</span><strong>{{ \App\Support\Money::cad($report->outstandingCents) }}</strong></article>
        <article class="metric"><span>Overdue</span><strong>{{ \App\Support\Money::cad($report->overdueCents) }}</strong></article>
    </div>
    <div class="work" style="margin-top:16px">
        <article class="card">
            <h2>Bills in this window</h2>
            @forelse ($report->billedLines as $line)
                <div class="list-row">
                    <span>
                        <span class="who">{{ $line['customer'] }}</span>
                        <span class="sub">Issued {{ \Carbon\CarbonImmutable::parse($line['issue_date'])->format('M j') }} · due {{ \Carbon\CarbonImmutable::parse($line['due_date'])->format('M j') }}</span>
                    </span>
                    <strong>{{ \App\Support\Money::cad($line['amount_cents']) }}</strong>
                </div>
            @empty
                <p class="empty">No charges issued in this window.</p>
            @endforelse
        </article>
        <article class="card">
            <h2>Payments received</h2>
            @forelse ($report->collectedLines as $line)
                <div class="list-row">
                    <span>
                        <span class="who">{{ $line['customer'] }}</span>
                        <span class="sub">{{ $line['received_date'] }} · {{ $line['method'] === 'e_transfer' ? 'E-transfer' : 'Cash' }}</span>
                    </span>
                    <strong>{{ \App\Support\Money::cad($line['amount_cents']) }}</strong>
                </div>
            @empty
                <p class="empty">No verified payments in this window.</p>
            @endforelse
        </article>
    </div>
    <article class="card">
        <h2>Still open</h2>
        @forelse ($report->outstandingLines as $line)
            <p><span class="who">{{ $line['customer'] }}</span> due {{ \Carbon\CarbonImmutable::parse($line['due_date'])->format('M j') }} · balance {{ \App\Support\Money::cad($line['balance_cents']) }}</p>
            <form method="post" action="{{ route('billing.payment', $line['id']) }}" class="row">
                @csrf
                <label>Amount<input name="amount" type="number" step="0.01" min="0.01" required></label>
                <label>Method
                    <select name="method"><option value="cash">Cash</option><option value="e_transfer">E-transfer</option></select>
                </label>
                <label>Received<input type="date" name="received_date" value="{{ $report->asOf }}" required></label>
                <button type="submit">Verify payment</button>
            </form>
        @empty
            <p class="empty">Nothing outstanding as of {{ $report->asOf }}.</p>
        @endforelse
    </article>
    <div class="work">
        <article class="card">
            <h2>“I paid” tasks</h2>
            @forelse ($tasks as $task)
                <p><span class="who">{{ $task->customer->displayName() }}</span> · {{ $task->note }}</p>
            @empty
                <p class="empty">No unverified payment claims.</p>
            @endforelse
        </article>
        <article class="card">
            <h2>Renewals waiting</h2>
            @forelse ($renewals as $prompt)
                <p><span class="who">{{ $prompt->subscription->customer->displayName() }}</span> · last covered {{ $prompt->last_covered_date?->toDateString() ?? 'n/a' }}</p>
                <form method="post" action="{{ route('renewals.approve', $prompt) }}">@csrf<button type="submit">Approve next cycle</button></form>
            @empty
                <p class="empty">No renewal is waiting.</p>
            @endforelse
        </article>
    </div>
@endsection
