<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

final class CloverOrderResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $id, public readonly array $payload) {}
}
