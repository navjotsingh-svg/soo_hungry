@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Money</p>
            <h1>Subscribers</h1>
            <p class="lede">A plan starts after the customer confirms the terms and you approve the address.</p>
        </div>
    </header>
    @forelse ($subscriptions as $subscription)
        <article class="card">
            <div class="person">
                <div>
                    <h2>{{ $subscription->customer->displayName() }}</h2>
                    <p>
                        <span class="pill {{ $subscription->status->value === 'active' ? 'good' : 'warn' }}">{{ \Illuminate\Support\Str::headline($subscription->status->value) }}</span>
                        {{ \Illuminate\Support\Str::headline($subscription->plan_kind->value) }} · {{ \App\Support\Money::cad($subscription->plan_price_cents) }}
                    </p>
                    <p>
                        {{ collect(['breakfast' => (int) $subscription->breakfast_portions, 'lunch' => (int) $subscription->lunch_portions, 'dinner' => (int) $subscription->dinner_portions])->filter()->map(fn (int $qty, string $label): string => $qty.' '.$label)->implode(' · ') }}
                        @if ($subscription->diet)<span class="pill {{ $subscription->diet === 'veg' ? 'good' : 'bad' }}">{{ \App\Models\TiffinMenuItem::DIETS[$subscription->diet] }}</span>@endif
                    </p>
                    @if ($subscription->weekSummary() !== '')
                        <p class="muted">{{ $subscription->weekSummary() }}</p>
                    @endif
                    <p class="muted">{{ $subscription->customer->phoneDisplay() }} · {{ $subscription->address }}</p>
                    <p>{{ $subscription->terms_summary }}</p>
                </div>
                <div class="credits">
                    <strong>{{ $subscription->portion_credits_remaining }}</strong>
                    <span class="muted">credits left</span>
                </div>
            </div>
            @if ($subscription->status->value === 'pending_approval')
                <form method="post" action="{{ route('subscribers.approve', $subscription) }}">@csrf<button type="submit">Approve address and start</button></form>
            @endif
        </article>
    @empty
        <article class="card"><p class="empty">No subscribers yet. A new customer sees the plan terms before the first delivery.</p></article>
    @endforelse
@endsection
