@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>Password</h1>
            <p class="lede">Change the password for {{ auth()->user()->email }}.</p>
        </div>
    </header>
    <form class="card narrow" method="post" action="{{ route('account.password.update') }}">
        @csrf
        @method('PUT')
        <label>Current password
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>New password
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Confirm password
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit">Change password</button>
    </form>
@endsection
