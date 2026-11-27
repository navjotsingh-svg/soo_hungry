@extends('layouts.staff')
@section('content')
    @php
        $meals = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
        $activeMeal = $slot;
        $dayLabel = \Carbon\CarbonImmutable::parse($date)->format('l, F j');
        $when = $date === $today ? 'Today' : ($date === $tomorrow ? 'Tomorrow' : $dayLabel);
    @endphp
    <header class="page">
        <div>
            <p class="kicker">Kitchen · {{ $when }}</p>
            <h1>{{ $dayLabel }}</h1>
            <p class="lede">Check today’s orders or tomorrow’s, then open breakfast, lunch, or dinner. Pack a meal when it goes out. Cancel takes that one meal off this date and keeps the credit.</p>
        </div>
        <form class="page-actions" method="get">
            @if ($activeMeal)<input type="hidden" name="slot" value="{{ $activeMeal }}">@endif
            <label>Another date<input type="date" name="date" value="{{ $date }}"></label>
            <button class="quiet" type="submit">Show</button>
        </form>
    </header>
    <nav class="segment" aria-label="Tiffin day">
        <a class="{{ $date === $today ? 'active' : '' }}" href="{{ route('packing.index', array_filter(['date' => $today, 'slot' => $activeMeal])) }}">Today</a>
        <a class="{{ $date === $tomorrow ? 'active' : '' }}" href="{{ route('packing.index', array_filter(['date' => $tomorrow, 'slot' => $activeMeal])) }}">Tomorrow</a>
    </nav>
    <nav class="segment" aria-label="Meal">
        <a class="{{ $activeMeal ? '' : 'active' }}" href="{{ route('packing.index', ['date' => $date]) }}">All meals</a>
        @foreach ($meals as $value => $label)
            <a class="{{ $activeMeal === $value ? 'active' : '' }}" href="{{ route('packing.index', ['date' => $date, 'slot' => $value]) }}">{{ $label }}</a>
        @endforeach
    </nav>
    <div class="grid">
        @foreach ($meals as $value => $label)
            <a class="metric {{ $activeMeal === $value ? 'active' : '' }}" href="{{ route('packing.index', ['date' => $date, 'slot' => $value]) }}">
                <span>{{ $label }} to pack</span>
                <strong>{{ $entries->where('slot', $value)->where('status', 'scheduled')->sum('quantity') }}</strong>
            </a>
        @endforeach
    </div>
    <div id="list" class="meal-lists">
        @foreach ($meals as $value => $label)
            @continue($activeMeal && $activeMeal !== $value)
            @php($rows = $entries->where('slot', $value))
            <article class="card meal-list">
                <div class="meal-list-head">
                    <h2>{{ $label }}</h2>
                    <span class="pill">{{ $rows->where('status', 'scheduled')->sum('quantity') }} to pack</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Customer</th><th>Diet</th><th class="num">Qty</th><th>Address</th><th>Notes</th><th></th></tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $entry)
                            <tr>
                                <td><strong>{{ $entry->customer->displayName() }}</strong></td>
                                <td>
                                    @if ($entry->diet)
                                        <span class="pill {{ $entry->diet === 'veg' ? 'good' : 'bad' }}">{{ \App\Models\TiffinMenuItem::DIETS[$entry->diet] ?? $entry->diet }}</span>
                                    @else
                                        {{ ucfirst($entry->meal_type) }}
                                    @endif
                                    <span class="pill {{ $entry->status === 'scheduled' ? 'good' : ($entry->status === 'fulfilled' ? '' : 'warn') }}">{{ \Illuminate\Support\Str::headline($entry->status) }}</span>
                                    @if ($entry->authorized_late_by)<span class="pill warn">Late approval</span>@endif
                                </td>
                                <td class="num">{{ $entry->quantity }}</td>
                                <td>{{ $entry->customer->address }}</td>
                                <td>{{ $entry->subscription->packing_notes }}</td>
                                <td class="actions">
                                    @if ($entry->status === 'scheduled')
                                        <form method="post" action="{{ route('packing.fulfill', $entry) }}">@csrf @if ($activeMeal)<input type="hidden" name="slot" value="{{ $activeMeal }}">@endif<button type="submit">Packed</button></form>
                                        <form method="post" action="{{ route('packing.skip', $entry) }}">@csrf @if ($activeMeal)<input type="hidden" name="slot" value="{{ $activeMeal }}">@endif<button class="quiet" type="submit">Cancel</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty">No {{ strtolower($label) }} orders for this date.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        @endforeach
    </div>
    <article class="card" id="assignments" style="margin-top:16px">
        <h2>Who gets tiffin</h2>
        <p class="muted">Tick each meal on the days they want it. Leave Saturday lunch clear if they skip that meal. Saving updates the kitchen list from today onward. It does not send a new bill.</p>
        @forelse ($customers as $customer)
            @php($plan = $customer->subscriptions->first(fn ($subscription) => in_array($subscription->status->value, ['active', 'paused'], true)))
            <form class="assign-card" method="post" action="{{ route('packing.assign', $customer) }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                @if ($activeMeal)<input type="hidden" name="slot" value="{{ $activeMeal }}">@endif
                <input type="hidden" name="slots_present" value="1">
                <input type="hidden" name="schedule_for" value="{{ $customer->id }}">
                <div class="assign-head">
                    <div>
                        <strong>{{ $customer->displayName() }}</strong>
                        <p class="muted">{{ $customer->phoneDisplay() }}</p>
                    </div>
                    <div class="assign-meals">
                    <label>Diet
                        <select name="diet">
                            <option value="">Choose</option>
                            @foreach (\App\Models\TiffinMenuItem::DIETS as $value => $label)
                                <option value="{{ $value }}" @selected(($plan->diet ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Breakfast<input type="number" name="breakfast" min="0" max="9" value="{{ $plan->breakfast_portions ?? 0 }}" required></label>
                    <label>Lunch<input type="number" name="lunch" min="0" max="9" value="{{ $plan->lunch_portions ?? 0 }}" required></label>
                    <label>Dinner<input type="number" name="dinner" min="0" max="9" value="{{ $plan->dinner_portions ?? 0 }}" required></label>
                    <button type="submit">Save</button>
                    </div>
                </div>
                @include('tiffin.week-grid', ['plan' => $plan, 'days' => $days, 'scheduleFor' => $customer->id])
            </form>
        @empty
            <p class="empty">Add a customer first, then choose their days and meals.</p>
        @endforelse
    </article>
    <article class="card" style="margin-top:16px">
        <h2>{{ $weekday }} menu</h2>
        <p class="muted"><a href="{{ route('tiffin-menu.index') }}">Edit the weekly menu</a></p>
        @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'] as $slot => $slotLabel)
            <section class="meal-block">
                <h3>{{ $slotLabel }}</h3>
                @foreach (['veg' => 'Veg', 'non_veg' => 'Non-veg'] as $diet => $dietLabel)
                    @php($planned = $menu->where('slot', $slot)->where('diet', $diet))
                    <div class="diet">
                        <span class="pill {{ $diet === 'veg' ? 'good' : 'bad' }}">{{ $dietLabel }}</span>
                        @if ($planned->isEmpty())
                            <p class="empty">Nothing planned.</p>
                        @else
                            <div class="menu-dishes">
                                @foreach ($planned as $item)
                                    <figure class="menu-dish">
                                        @if ($item->photoUrl())
                                            <img src="{{ $item->photoUrl() }}" alt="{{ $item->name }}">
                                        @endif
                                        <figcaption>{{ $item->name }}</figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @endforeach
    </article>
    @if ($pending->isNotEmpty())
        <article class="card" style="margin-top:16px">
            <h2>Still waiting on a yes</h2>
            @foreach ($pending as $proposal)
                <p>{{ $proposal->summary }}</p>
                @if ($canApprove && ($proposal->payload['late'] ?? []) !== [])
                    <form method="post" action="{{ route('actions.approve-late', $proposal) }}">@csrf<button type="submit">Approve late exception</button></form>
                @endif
            @endforeach
        </article>
    @endif
@endsection
