@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Service</p>
            <h1>Pickup orders</h1>
            <p class="lede">{{ $currentMerchant->name }} handles tiffin. Pickup orders are kept on a café workspace.</p>
        </div>
    </header>
    <article class="card">
        <div class="toolbar">
        @forelse ($cafes as $cafe)
            <form method="post" action="{{ route('merchant.switch') }}">
                @csrf
                <input type="hidden" name="merchant_id" value="{{ $cafe->merchant_id }}">
                <input type="hidden" name="next" value="pickup">
                <button type="submit">Open {{ $cafe->merchant->name }}</button>
            </form>
        @empty
            <p class="empty">This account has no café workspace.</p>
        @endforelse
        </div>
    </article>
@endsection
