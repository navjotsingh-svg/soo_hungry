<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $memberships = MerchantUser::query()->where('user_id', $user->id)->with('merchant')->get();
        if ($memberships->isEmpty()) {
            abort(403, 'This account is not on a merchant workspace.');
        }

        $merchantId = (int) $request->session()->get('merchant_id');
        $membership = $memberships->firstWhere('merchant_id', $merchantId) ?? $memberships->first();
        $request->session()->put('merchant_id', $membership->merchant_id);
        $request->attributes->set('membership', $membership);
        $request->attributes->set('merchant', $membership->merchant);
        view()->share('currentMerchant', $membership->merchant);
        view()->share('membership', $membership);
        view()->share('navMerchants', $memberships);

        return $next($request);
    }
}
