<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

final class PickupQuote
{
    /**
     * @param  list<string>  $questions
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public readonly bool $ready,
        public readonly array $questions,
        public readonly int $subtotalCents,
        public readonly int $taxCents,
        public readonly int $totalCents,
        public readonly array $lines,
    ) {}
}
