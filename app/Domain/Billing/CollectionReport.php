<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Bill;
use App\Models\Merchant;
use App\Models\Payment;
use Carbon\CarbonImmutable;

final class CollectionReport
{
    public function __construct(private readonly MonthEndCalculator $calculator) {}

    public function forMerchant(Merchant $merchant, string $month, ?CarbonImmutable $today = null): MonthEndFigures
    {
        $today = ($today ?? CarbonImmutable::now($merchant->timezone))->timezone($merchant->timezone);
        $asOf = $this->calculator->asOf($month, $today);

        $bills = Bill::query()
            ->where('merchant_id', $merchant->id)
            ->get()
            ->map(fn (Bill $bill): array => [
                'id' => $bill->id,
                'customer' => $bill->customer->displayName(),
                'issue_date' => $bill->issue_date->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
                'amount_cents' => (int) $bill->amount_cents,
                'status' => $bill->status,
                'cycle_key' => $bill->cycle_key,
            ])->all();

        $payments = Payment::query()
            ->where('merchant_id', $merchant->id)
            ->get()
            ->map(fn (Payment $payment): array => [
                'bill_id' => $payment->bill_id,
                'received_date' => $payment->received_date->toDateString(),
                'amount_cents' => (int) $payment->amount_cents,
                'method' => $payment->method,
                'customer' => $payment->customer->displayName(),
            ])->all();

        return $this->calculator->summarize($bills, $payments, $month, $asOf);
    }
}
