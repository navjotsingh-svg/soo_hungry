<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\CollectionReport;
use App\Domain\Tiffin\RenewalService;
use App\Models\Bill;
use App\Models\PaymentTask;
use App\Models\RenewalPrompt;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends StaffController
{
    public function index(Request $request, CollectionReport $reports): View
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        $month = (string) $request->query('month', CarbonImmutable::now($merchant->timezone)->format('Y-m'));
        $report = $reports->forMerchant($merchant, $month);
        $tasks = PaymentTask::query()->where('merchant_id', $merchant->id)->where('status', 'open')->with('customer', 'bill')->get();
        $renewals = RenewalPrompt::query()->where('merchant_id', $merchant->id)->whereNull('bill_id')->with('subscription.customer')->get();

        return view('billing.index', compact('month', 'report', 'tasks', 'renewals'));
    }

    public function payment(Request $request, Bill $bill, BillingService $billing): RedirectResponse
    {
        abort_unless($bill->merchant_id === $this->merchant($request)->id, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,e_transfer'],
            'received_date' => ['required', 'date'],
        ]);
        $billing->receive($bill, $request->user(), (int) round(((float) $data['amount']) * 100), $data['method'], $data['received_date']);

        return back()->with('status', 'Payment recorded.');
    }

    public function approveRenewal(Request $request, RenewalPrompt $prompt, RenewalService $renewals): RedirectResponse
    {
        abort_unless($prompt->merchant_id === $this->merchant($request)->id, 404);
        $renewals->approve($prompt, $request->user());

        return back()->with('status', 'Next cycle approved. Record the payment separately.');
    }
}
