<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends StaffController
{
    public function edit(Request $request): View
    {
        $merchant = $this->merchant($request)->load('modules', 'whatsappAccount', 'cutoff', 'cloverConnection', 'locations');

        return view('settings.edit', compact('merchant'));
    }

    public function update(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'reminder_local_time' => ['required', 'date_format:H:i'],
            'ordering_paused' => ['nullable', 'boolean'],
            'can_create_customers' => ['nullable', 'boolean'],
            'existing_number_status' => ['required', 'in:unchecked,eligible,ineligible'],
            'production_access' => ['nullable', 'boolean'],
            'google_chat_status' => ['required', 'in:unknown,unavailable,offered'],
            'print_mode' => ['nullable', 'in:explicit_print,creation_triggers_print'],
        ]);

        $merchant->update(['reminder_local_time' => $data['reminder_local_time'].':00']);
        $merchant->whatsappAccount?->update([
            'existing_number_status' => $data['existing_number_status'],
            'google_chat_status' => $data['google_chat_status'],
            'production_access_confirmed_at' => $request->boolean('production_access')
                ? ($merchant->whatsappAccount->production_access_confirmed_at ?? now())
                : null,
        ]);
        $merchant->cloverConnection?->update([
            'ordering_paused' => $request->boolean('ordering_paused'),
            'can_create_customers' => $request->boolean('can_create_customers'),
            'print_mode' => $data['print_mode'] ?? $merchant->cloverConnection->print_mode,
        ]);

        return back()->with('status', 'Settings saved.');
    }
}
