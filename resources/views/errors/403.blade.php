@extends('layouts.public')
@section('content')
    <div class="public-wrap">
        <img class="logo" src="{{ asset('brand/logo.png') }}" alt="Soo Hungry" width="300" height="56">
        <h1>You don’t have access to this page.</h1>
        <p>Your role can use the pages in the sidebar. An owner can open dues and settings.</p>
        <p><a class="button" href="{{ route('dashboard') }}">Back to today</a></p>
    </div>
@endsection
