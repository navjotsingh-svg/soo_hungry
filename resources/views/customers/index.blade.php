@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Records</p>
            <h1>Customers</h1>
            <p class="lede">People for {{ $currentMerchant->name }}.@if ($currentMerchant->hasModule('tiffin')) Add their tiffin days and meals on the same form.@else Adding someone here does not start a pickup order.@endif</p>
        </div>
        <a class="button" href="{{ route('customers.create') }}">Add customer</a>
    </header>
    <article class="card">
        <form class="search" method="get">
            <label>Search
                <input name="q" value="{{ $search }}" placeholder="Name or phone">
            </label>
            <button class="quiet" type="submit">Find</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Phone</th><th>Address</th><th>Plans</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td><strong>{{ $customer->displayName() }}</strong></td>
                        <td>{{ $customer->phoneDisplay() }}</td>
                        <td>{{ $customer->address ?: '—' }}</td>
                        <td class="num">{{ $customer->subscriptions_count }}</td>
                        <td class="actions"><a href="{{ route('customers.edit', $customer) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No customers match. Add one, or wait for the first WhatsApp message.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($customers->hasPages())
            <nav class="pager" aria-label="Pages">
                @if ($customers->onFirstPage())
                    <span class="muted">Previous</span>
                @else
                    <a href="{{ $customers->previousPageUrl() }}">Previous</a>
                @endif
                <span class="muted">Page {{ $customers->currentPage() }}</span>
                @if ($customers->hasMorePages())
                    <a href="{{ $customers->nextPageUrl() }}">Next</a>
                @else
                    <span class="muted">Next</span>
                @endif
            </nav>
        @endif
    </article>
@endsection
