<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

final class RouteDecision
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $question = null,
        public readonly ?string $handoffReason = null,
    ) {}
}
