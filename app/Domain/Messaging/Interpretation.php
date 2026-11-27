<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

final class Interpretation
{
    /**
     * @param  list<string>  $skipSlots
     * @param  list<string>  $keepSlots
     * @param  array{type:string, day?:string, start?:string, end?:string}  $when
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $ambiguousPunjabi = false,
        public readonly ?string $handoffReason = null,
        public readonly ?int $selection = null,
        public readonly array $skipSlots = [],
        public readonly array $keepSlots = [],
        public readonly array $when = ['type' => 'unspecified'],
        public readonly bool $pause = false,
        public readonly bool $resume = false,
        public readonly bool $openEnded = false,
        public readonly ?int $portions = null,
        public readonly array $lines = [],
        public readonly ?string $pickupTime = null,
        public readonly bool $pickupTomorrow = false,
        public readonly bool $modifierConflict = false,
    ) {}

    public static function optOut(): self
    {
        return new self('opt_out');
    }

    public static function confirmation(): self
    {
        return new self('confirmation');
    }

    public static function selection(int $number): self
    {
        return new self('selection', selection: $number);
    }

    public static function handoff(string $reason): self
    {
        return new self('handoff', handoffReason: $reason);
    }

    public static function paymentClaim(): self
    {
        return new self('payment_claim');
    }

    public static function renewal(): self
    {
        return new self('renewal');
    }

    public static function ambiguous(bool $punjabi): self
    {
        return new self('ambiguous', ambiguousPunjabi: $punjabi);
    }

    public static function unclear(): self
    {
        return new self('unclear');
    }
}
