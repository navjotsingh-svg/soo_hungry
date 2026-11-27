@extends('layouts.staff')
@php
    $optionText = function (array $group): string {
        return collect($group['options'] ?? [])->map(function (array $option): string {
            return $option['name'].' | '.number_format(((int) $option['price_cents']) / 100, 2, '.', '');
        })->implode("\n");
    };
@endphp
@section('content')
    <a class="back" href="{{ route('menu.index') }}">Back to menu</a>
    <header class="page">
        <div>
            <p class="kicker">Records</p>
            <h1>{{ $item->exists ? $item->name : 'New menu item' }}</h1>
            <p class="lede">Shelf price is before tax. Required options are asked in the WhatsApp chat before the order is confirmed.</p>
        </div>
    </header>
    <form class="card narrow" method="post" action="{{ $item->exists ? route('menu.update', $item) : route('menu.store') }}">
        @csrf
        @if ($item->exists) @method('PUT') @endif
        <div class="row">
            <label>Name
                <input name="name" value="{{ old('name', $item->name) }}" required maxlength="120">
            </label>
            <label>Price
                <input name="price" type="number" step="0.01" min="0" max="999.99" value="{{ old('price', $item->exists ? number_format($item->price_cents / 100, 2, '.', '') : '') }}" required>
            </label>
            <label>Tax %
                <input name="tax_percent" type="number" step="0.01" min="0" max="30" value="{{ old('tax_percent', number_format(($item->tax_rate_bps ?? 500) / 100, 2, '.', '')) }}" required>
            </label>
        </div>
        <label class="choice"><input type="checkbox" name="available" value="1" @checked($errors->any() ? old('available') : ($item->available ?? true))> Available to order</label>
        @foreach ($groups as $index => $group)
            <fieldset>
                <legend>Option group {{ $index + 1 }}</legend>
                <div class="row">
                    <label>Group name
                        <input name="groups[{{ $index }}][name]" value="{{ old("groups.$index.name", $group['name'] ?? '') }}" placeholder="Size">
                    </label>
                    <label class="choice"><input type="checkbox" name="groups[{{ $index }}][required]" value="1" @checked($errors->any() ? old("groups.$index.required") : ($group['required'] ?? false))> Required</label>
                </div>
                <label>Options
                    <textarea name="groups[{{ $index }}][options]" rows="4" placeholder="Small | 0.00&#10;Large | 1.00">{{ old("groups.$index.options", $optionText($group)) }}</textarea>
                    <span class="help">One option per line: name, a bar, then the extra price. Leave the group blank if this item has no options.</span>
                </label>
            </fieldset>
        @endforeach
        <button type="submit">{{ $item->exists ? 'Save item' : 'Add item' }}</button>
    </form>
@endsection
