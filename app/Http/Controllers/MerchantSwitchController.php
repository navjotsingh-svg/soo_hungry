<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MerchantUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MerchantSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'next' => ['nullable', 'in:pickup'],
        ]);
        $merchantId = (int) $data['merchant_id'];
        $belongs = MerchantUser::query()
            ->where('user_id', $request->user()->id)
            ->where('merchant_id', $merchantId)
            ->exists();
        abort_unless($belongs, 403);
        $request->session()->put('merchant_id', $merchantId);

        if (($data['next'] ?? null) === 'pickup') {
            return redirect()->route('cafe.index');
        }

        return redirect()->route('dashboard');
    }
}
