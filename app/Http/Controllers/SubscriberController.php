<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tiffin\SubscriptionEnrollment;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriberController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        $subscriptions = Subscription::query()
            ->where('merchant_id', $merchant->id)
            ->with('customer')
            ->orderByDesc('id')
            ->get();

        return view('tiffin.subscribers', compact('subscriptions'));
    }

    public function approve(Request $request, Subscription $subscription, SubscriptionEnrollment $enrollment): RedirectResponse
    {
        abort_unless($subscription->merchant_id === $this->merchant($request)->id, 404);
        $enrollment->ownerApprove($subscription, $request->user());

        return back()->with('status', 'Address approved. The plan is active.');
    }
}
