<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            return back()->withErrors(['email' => 'Those credentials do not match a staff account.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $membership = $request->user()->memberships()->where('active', true)->first();
        if ($membership === null) {
            Auth::logout();

            return back()->withErrors(['email' => 'This account is inactive.']);
        }

        $request->session()->put('merchant_id', $membership->merchant_id);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
