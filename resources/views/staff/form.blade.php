@extends('layouts.staff')
@section('content')
    <a class="back" href="{{ route('staff.index') }}">Back to staff</a>
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>{{ $member ? $member->user->name : 'New staff' }}</h1>
            <p class="lede">{{ $member ? 'Update this person and their role for '.$currentMerchant->name.'.' : 'They can sign in with this email and password.' }}</p>
        </div>
    </header>
    <form class="card narrow" method="post" action="{{ $member ? route('staff.update', $member->user) : route('staff.store') }}">
        @csrf
        @if ($member) @method('PUT') @endif
        <label>Name
            <input name="name" value="{{ old('name', $member?->user->name) }}" required maxlength="120">
        </label>
        <label>Email
            <input type="email" name="email" value="{{ old('email', $member?->user->email) }}" required maxlength="255" autocomplete="off">
        </label>
        <label>Phone
            <input name="phone" value="{{ old('phone', $member?->user->phone) }}" maxlength="30">
        </label>
        <label>Role
            <select name="role" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $member?->role->value ?? 'service') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>Status
            <select name="active" required>
                <option value="1" @selected(old('active', $member === null || $member->active ? '1' : '0') == '1')>Active</option>
                <option value="0" @selected(old('active', $member === null || $member->active ? '1' : '0') == '0')>Inactive</option>
            </select>
        </label>
        @unless ($member)
            <label>Password
                <input type="password" name="password" required minlength="8" autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
            </label>
        @endunless
        <button type="submit">{{ $member ? 'Save staff' : 'Add staff' }}</button>
    </form>
    @if ($member)
        <form class="card narrow" id="password" method="post" action="{{ route('staff.password', $member->user) }}">
            @csrf
            @method('PUT')
            <h2>Change password</h2>
            <p class="help">This replaces the password for {{ $member->user->email }}. They use it the next time they sign in.</p>
            <label>New password
                <input type="password" name="password" required minlength="8" autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
            </label>
            <button type="submit">Change password</button>
        </form>
    @endif
@endsection
