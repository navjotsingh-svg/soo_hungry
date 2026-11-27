<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Auditor;
use App\Enums\StaffRole;
use App\Models\MerchantUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffDirectoryController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        $staff = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->with('user')
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        return view('staff.index', [
            'staff' => $staff,
            'roles' => StaffRole::cases(),
        ]);
    }

    public function create(): View
    {
        return view('staff.form', [
            'member' => null,
            'roles' => StaffRole::cases(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'active' => ['required', 'boolean'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => $this->phone($data['phone'] ?? null),
            'password' => $data['password'],
        ]);
        $membership = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $data['role'],
            'active' => $request->boolean('active'),
        ]);
        $auditor->record($merchant->id, $request->user(), 'staff.created', $user, 'staff', [
            'role' => $membership->role->value,
            'active' => $membership->active,
        ]);

        return redirect()->route('staff.index')->with('status', $user->name.' can sign in.');
    }

    public function edit(Request $request, User $user): View
    {
        return view('staff.form', [
            'member' => $this->membership($request, $user),
            'roles' => StaffRole::cases(),
        ]);
    }

    public function update(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $membership = $this->membership($request, $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'active' => ['required', 'boolean'],
        ]);
        $role = StaffRole::from($data['role']);
        $active = $request->boolean('active');
        $this->guardLastOwner($merchant->id, $membership, $role, $active);
        $user->update([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => $this->phone($data['phone'] ?? null),
        ]);
        $membership->update(['role' => $role, 'active' => $active]);
        $auditor->record($merchant->id, $request->user(), 'staff.updated', $user, 'staff', [
            'role' => $role->value,
            'active' => $active,
        ]);

        return redirect()->route('staff.edit', $user)->with('status', $user->name.' was updated.');
    }

    public function password(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $this->membership($request, $user);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user->update(['password' => $data['password']]);
        $auditor->record($merchant->id, $request->user(), 'staff.password_changed', $user, 'staff');

        return redirect()->route('staff.edit', $user)->with('status', 'Password updated for '.$user->name.'.');
    }

    private function membership(Request $request, User $user): MerchantUser
    {
        $membership = MerchantUser::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->where('user_id', $user->id)
            ->first();
        abort_unless($membership !== null, 404);

        return $membership;
    }

    private function guardLastOwner(int $merchantId, MerchantUser $membership, StaffRole $role, bool $active): void
    {
        $leaving = $membership->role === StaffRole::Owner && $membership->active
            && ($role !== StaffRole::Owner || ! $active);
        if (! $leaving) {
            return;
        }
        $owners = MerchantUser::query()
            ->where('merchant_id', $merchantId)
            ->where('role', StaffRole::Owner)
            ->where('active', true)
            ->count();
        if ($owners >= 2) {
            return;
        }
        throw ValidationException::withMessages([
            $role !== StaffRole::Owner ? 'role' : 'active' => 'This workspace needs at least one active owner.',
        ]);
    }

    private function phone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }
}
