@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Records</p>
            <h1>Menu</h1>
            <p class="lede">Sellable items for {{ $currentMerchant->name }}. Prices are before tax. A saved change is what the next WhatsApp quote uses.</p>
        </div>
        <a class="button" href="{{ route('menu.create') }}">Add item</a>
    </header>
    <article class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Item</th><th class="num">Price</th><th class="num">Tax</th><th>Options</th><th></th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td><strong>{{ $item->name }}</strong></td>
                        <td class="num">{{ \App\Support\Money::cad($item->price_cents) }}</td>
                        <td class="num">{{ number_format($item->tax_rate_bps / 100, 2) }}%</td>
                        <td>{{ collect($item->modifier_groups ?? [])->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td><span class="pill {{ $item->available ? 'good' : 'warn' }}">{{ $item->available ? 'Available' : 'Sold out' }}</span></td>
                        <td class="actions"><a href="{{ route('menu.edit', $item) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">The menu is empty. Add the first item before accepting pickup orders.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
