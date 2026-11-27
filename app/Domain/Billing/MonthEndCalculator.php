<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;

/**
 * A closed month is reported as of its last day, so a payment received next
 * month does not rewrite the earlier outstanding balance.
 * The current month is reported through today in the merchant's time zone.
 */
final class MonthEndCalculator
{
    public function asOf(string $month, CarbonImmutable $todayLocal): string
    {
        $today = $todayLocal->startOfDay();
        $start = CarbonImmutable::parse($month.'-01', $todayLocal->timezone)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        if ($today->lt($start)) {
            return $start->subDay()->toDateString();
        }

        if ($today->gt($end)) {
            return $end->toDateString();
        }

        return $today->toDateString();
    }

    /**
     * @param  list<array{id:int|string, issue_date:string, due_date:string, amount_cents:int, status?:string}>  $bills
     * @param  list<array{bill_id:int|string, received_date:string, amount_cents:int}>  $payments
     */
    public function summarize(array $bills, array $payments, string $month, string $asOf): MonthEndFigures
    {
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth()->toDateString();
        $monthEnd = CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
        $windowEnd = min($monthEnd, $asOf);

        $billed = 0;
        $billedLines = [];
        foreach ($bills as $bill) {
            if (($bill['status'] ?? 'issued') === 'void') {
                continue;
            }
            if ($bill['issue_date'] >= $start && $bill['issue_date'] <= $windowEnd) {
                $billed += $bill['amount_cents'];
                $billedLines[] = $bill;
            }
        }

        $collected = 0;
        $collectedLines = [];
        foreach ($payments as $payment) {
            if ($payment['received_date'] >= $start && $payment['received_date'] <= $windowEnd) {
                $collected += $payment['amount_cents'];
                $collectedLines[] = $payment;
            }
        }

        $outstanding = 0;
        $overdue = 0;
        $outstandingLines = [];
        $overdueLines = [];

        foreach ($bills as $bill) {
            if (($bill['status'] ?? 'issued') === 'void') {
                continue;
            }
            if ($bill['issue_date'] > $windowEnd) {
                continue;
            }

            $paid = 0;
            foreach ($payments as $payment) {
                if ((string) $payment['bill_id'] === (string) $bill['id'] && $payment['received_date'] <= $windowEnd) {
                    $paid += $payment['amount_cents'];
                }
            }

            $balance = $bill['amount_cents'] - $paid;
            if ($balance <= 0) {
                continue;
            }

            $line = $bill + ['balance_cents' => $balance, 'paid_cents' => $paid];
            $outstanding += $balance;
            $outstandingLines[] = $line;

            if ($bill['due_date'] < $windowEnd) {
                $overdue += $balance;
                $overdueLines[] = $line;
            }
        }

        return new MonthEndFigures(
            billedCents: $billed,
            collectedCents: $collected,
            outstandingCents: $outstanding,
            overdueCents: $overdue,
            asOf: $windowEnd,
            billedLines: $billedLines,
            collectedLines: $collectedLines,
            outstandingLines: $outstandingLines,
            overdueLines: $overdueLines,
        );
    }
}
