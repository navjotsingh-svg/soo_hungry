@extends('layouts.public')
@section('content')
    <div class="chat-card">
        <img class="mark" src="{{ asset('brand/icon.png') }}" alt="" width="64" height="64">
        <p class="brand">{{ $merchant->name }}</p>
        <h1>Message us on WhatsApp</h1>
        <p>No app and no account. Orders and tiffin changes stay in this chat.</p>
        @if ($link)
            <p><a class="button" href="{{ $link }}">Open WhatsApp</a></p>
            <p class="muted">Print this page as a QR code. The destination is:</p>
            <p class="link-line">{{ $link }}</p>
        @else
            <p>This location does not have a WhatsApp number yet.</p>
        @endif
    </div>
@endsection