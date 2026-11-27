@extends('layouts.staff')
@section('content')
    <a class="back" href="{{ route('customers.index') }}">Back to customers</a>
    <header class="page">
        <div>
            <p class="kicker">Records</p>
            <h1>{{ $customer->exists ? $customer->displayName() : 'New customer' }}</h1>
            <p class="lede">This record stays with {{ $currentMerchant->name }}.@if ($currentMerchant->hasModule('tiffin')) Set the tiffin days and meals here, and the packing list follows.@endif</p>
        </div>
    </header>
    <form id="customer-form" class="card narrow" method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif
        <label>Name
            <input name="name" value="{{ old('name', $customer->name) }}" required maxlength="120">
        </label>
        <label>Phone
            <input name="phone" value="{{ old('phone', $customer->exists ? $customer->phoneDisplay() : '') }}" required placeholder="604 555 0100">
            <span class="help">Stored as a full number so WhatsApp can match the same person later.</span>
        </label>
        <label>Address
            <input name="address" value="{{ old('address', $customer->address) }}" maxlength="200" placeholder="Street, city">
        </label>
        <label>Language
            <select name="language">
                <option value="en" @selected(old('language', $customer->language) === 'en')>English</option>
                <option value="pa-Latn" @selected(old('language', $customer->language) === 'pa-Latn')>Roman Punjabi</option>
            </select>
        </label>
        @if ($currentMerchant->hasModule('tiffin'))
            <fieldset>
                <legend>Tiffin schedule</legend>
                <span class="help">Tick each meal on the days they want it. Leave Saturday lunch clear if they skip that meal. The number is how many portions on each ticked day. Leave the grid blank to save the customer only.</span>
                <input type="hidden" name="slots_present" value="1">
                <input type="hidden" name="schedule_for" value="{{ $customer->exists ? $customer->id : 'new' }}">
                @include('tiffin.week-grid', ['plan' => $plan, 'days' => $days, 'scheduleFor' => $customer->exists ? $customer->id : 'new'])
                @error('schedule_days')<p class="error">{{ $message }}</p>@enderror
                <div class="row">
                    <label>Diet
                        <select name="diet">
                            <option value="">Choose</option>
                            @foreach (\App\Models\TiffinMenuItem::DIETS as $value => $label)
                                <option value="{{ $value }}" @selected(old('diet', $plan->diet ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Breakfast
                        <input type="number" name="breakfast" min="0" max="9" value="{{ old('breakfast', $plan->breakfast_portions ?? 0) }}">
                    </label>
                    <label>Lunch
                        <input type="number" name="lunch" min="0" max="9" value="{{ old('lunch', $plan->lunch_portions ?? 0) }}">
                    </label>
                    <label>Dinner
                        <input type="number" name="dinner" min="0" max="9" value="{{ old('dinner', $plan->dinner_portions ?? 0) }}">
                    </label>
                </div>
                @error('diet')<p class="error">{{ $message }}</p>@enderror
            </fieldset>
        @endif
        @if ($customer->exists && $customer->relationLoaded('subscriptions') && $customer->subscriptions->isNotEmpty())
            <p class="muted">Tiffin plans on this record: {{ $customer->subscriptions->map(fn ($plan) => \Illuminate\Support\Str::headline($plan->status->value))->implode(', ') }}.</p>
        @endif
        <button type="submit" form="customer-form">{{ $customer->exists ? 'Save customer' : 'Add customer' }}</button>
    </form>
@endsection
