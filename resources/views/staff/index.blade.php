@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Account</p>
            <h1>Staff</h1>
            <p class="lede">People for {{ $currentMerchant->name }}. Inactive staff stay on the list and cannot sign in to this workspace.</p>
        </div>
        <a class="button" href="{{ route('staff.create') }}">Add staff</a>
    </header>
    <article class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($staff as $member)
                    <tr>
                        <td><strong>{{ $member->user->name }}</strong></td>
                        <td>{{ $member->user->email }}</td>
                        <td><span class="pill">{{ $member->role->label() }}</span></td>
                        <td><span class="pill {{ $member->active ? 'good' : 'warn' }}">{{ $member->active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="actions"><a href="{{ route('staff.edit', $member->user) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No staff yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
