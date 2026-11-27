<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;

final class MonthEndFigures
{
    /**
     * @param  list<array<string, mixed>>  $billedLines
     * @param  list<array<string, mixed>>  $collectedLines
     * @param  list<array<string, mixed>>  $outstandingLines
     * @param  list<array<string, mixed>>  $overdueLines
     */
    public function __construct(
        public readonly int $billedCents,
        public readonly int $collectedCents,
        public readonly int $outstandingCents,
        public readonly int $overdueCents,
        public readonly string $asOf,
        public readonly array $billedLines,
        public readonly array $collectedLines,
        public readonly array $outstandingLines,
        public readonly array $overdueLines,
    ) {}
}
