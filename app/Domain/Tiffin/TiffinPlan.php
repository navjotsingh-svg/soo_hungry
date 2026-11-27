<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

final class TiffinPlan
{
    /**
     * @param  list<array<string, mixed>>  $apply
     * @param  list<array<string, mixed>>  $late
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly ?string $question,
        public readonly array $apply,
        public readonly array $late,
        public readonly array $notes,
        public readonly ?string $expiresAt,
        public readonly string $summary,
        public readonly bool $openEnded = false,
        public readonly bool $resume = false,
        public readonly ?string $resumeDate = null,
    ) {}

    public function staffOnly(): bool
    {
        return $this->question === null && $this->apply === [] && $this->late !== [];
    }
}
