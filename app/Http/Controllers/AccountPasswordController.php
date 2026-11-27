<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountPasswordController extends StaffController
{
    public function edit(): View
    {
        return view('account.password');
    }

    public function update(Request $request, Auditor $auditor): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password does not match.',
            ]);
        }
        $user->update(['password' => $data['password']]);
        $auditor->record($this->merchant($request)->id, $user, 'account.password_changed', $user, 'staff');

        return redirect()->route('account.password')->with('status', 'Your password was changed.');
    }
}
