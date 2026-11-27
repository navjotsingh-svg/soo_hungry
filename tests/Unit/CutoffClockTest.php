<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tiffin\CutoffClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CutoffClockTest extends TestCase
{
    private CutoffClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new CutoffClock();
    }

    public function test_a_request_at_759_confirmed_at_801_is_timely(): void
    {
        $wednesday = CarbonImmutable::parse('2026-11-25 12:00', 'America/Vancouver');
        $identified = CarbonImmutable::parse('2026-11-24 19:59', 'America/Vancouver');
        $confirmed = CarbonImmutable::parse('2026-11-24 20:01', 'America/Vancouver');

        $this->assertSame('timely', $this->clock->eligibility($identified, $confirmed, $wednesday, '20:00', 10));
    }

    public function test_a_first_request_at_805_is_late_even_inside_the_grace_window(): void
    {
        $wednesday = CarbonImmutable::parse('2026-11-25 12:00', 'America/Vancouver');
        $late = CarbonImmutable::parse('2026-11-24 20:05', 'America/Vancouver');

        $this->assertSame('late', $this->clock->eligibility($late, $late, $wednesday, '20:00', 10));
    }

    public function test_a_timely_request_confirmed_after_grace_is_judged_again(): void
    {
        $wednesday = CarbonImmutable::parse('2026-11-25 12:00', 'America/Vancouver');
        $identified = CarbonImmutable::parse('2026-11-24 19:59', 'America/Vancouver');
        $confirmed = CarbonImmutable::parse('2026-11-24 20:11', 'America/Vancouver');

        $this->assertSame('late', $this->clock->eligibility($identified, $confirmed, $wednesday, '20:00', 10));
    }

    public function test_the_same_805_request_is_timely_for_a_10_pm_cutoff(): void
    {
        $wednesday = CarbonImmutable::parse('2026-11-25 12:00', 'America/Vancouver');
        $at = CarbonImmutable::parse('2026-11-24 20:05', 'America/Vancouver');

        $this->assertSame('timely', $this->clock->eligibility($at, $at, $wednesday, '22:00', 10));
    }
}
