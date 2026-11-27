<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

final class CloverPrintResult
{
    public function __construct(public readonly string $status, public readonly ?string $eventId) {}
}
