<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Billing\MonthEndCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MonthEndCalculatorTest extends TestCase
{
    public function test_november_stays_historical_after_the_december_payment(): void
    {
        $calculator = new MonthEndCalculator();
        $bills = [[
            'id' => 1,
            'issue_date' => '2026-11-25',
            'due_date' => '2026-11-30',
            'amount_cents' => 10000,
            'status' => 'partial',
        ]];
        $payments = [
            ['bill_id' => 1, 'received_date' => '2026-11-28', 'amount_cents' => 4000],
            ['bill_id' => 1, 'received_date' => '2026-12-02', 'amount_cents' => 6000],
        ];
        $december = CarbonImmutable::parse('2026-12-15', 'America/Vancouver');

        $november = $calculator->summarize($bills, $payments, '2026-11', $calculator->asOf('2026-11', $december));
        $this->assertSame(10000, $november->billedCents);
        $this->assertSame(4000, $november->collectedCents);
        $this->assertSame(6000, $november->outstandingCents);
        $this->assertSame(0, $november->overdueCents);

        $decemberReport = $calculator->summarize($bills, $payments, '2026-12', $calculator->asOf('2026-12', $december));
        $this->assertSame(0, $decemberReport->billedCents);
        $this->assertSame(6000, $decemberReport->collectedCents);
        $this->assertSame(0, $decemberReport->outstandingCents);
    }

    public function test_the_current_month_stops_at_today(): void
    {
        $calculator = new MonthEndCalculator();
        $today = CarbonImmutable::parse('2026-11-26', 'America/Vancouver');
        $report = $calculator->summarize(
            [['id' => 1, 'issue_date' => '2026-11-25', 'due_date' => '2026-11-30', 'amount_cents' => 10000]],
            [['bill_id' => 1, 'received_date' => '2026-11-28', 'amount_cents' => 4000]],
            '2026-11',
            $calculator->asOf('2026-11', $today),
        );

        $this->assertSame('2026-11-26', $report->asOf);
        $this->assertSame(10000, $report->billedCents);
        $this->assertSame(0, $report->collectedCents);
        $this->assertSame(10000, $report->outstandingCents);
    }
}
