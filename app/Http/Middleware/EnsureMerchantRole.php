<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership && in_array($membership->role->value, $roles, true), 403);

        return $next($request);
    }
}
