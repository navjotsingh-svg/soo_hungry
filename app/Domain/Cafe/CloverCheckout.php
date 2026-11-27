<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

final class CloverCheckout
{
    public function __construct(
        public readonly int $subtotalCents,
        public readonly int $taxCents,
        public readonly int $totalCents,
    ) {}
}
