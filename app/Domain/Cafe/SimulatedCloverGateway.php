<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CloverConnection;

/**
 * Sandbox stand-in used until the merchant's Clover token is connected.
 * Idempotency keys stick, including when a create times out after Clover accepted it.
 */
final class SimulatedCloverGateway implements CloverGateway
{
    public int $creates = 0;

    public int $prints = 0;

    public int $customerCreates = 0;

    public bool $uncertainOnce = false;

    public string $printStatus = 'printed';

    public ?int $checkoutTotalOverride = null;

    /** @var array<string, CloverOrderResult> */
    private array $orders = [];

    /** @var array<string, list<array{id:string, name:string, phone:string}>> */
    public array $customers = [];

    public function checkout(CloverConnection $connection, array $payload, int $quotedTotalCents): CloverCheckout
    {
        if ($this->checkoutTotalOverride !== null) {
            $total = $this->checkoutTotalOverride;
            $this->checkoutTotalOverride = null;

            return new CloverCheckout($total, 0, $total);
        }

        return new CloverCheckout($quotedTotalCents, 0, $quotedTotalCents);
    }

    public function createOrder(CloverConnection $connection, array $payload, string $idempotencyKey): CloverOrderResult
    {
        if (isset($this->orders[$idempotencyKey])) {
            return $this->orders[$idempotencyKey];
        }

        $this->creates++;
        $result = new CloverOrderResult('CLV'.substr(sha1($idempotencyKey), 0, 10), $payload);
        $this->orders[$idempotencyKey] = $result;

        if ($this->uncertainOnce) {
            $this->uncertainOnce = false;
            throw new CloverUncertainException('Clover timed out after accepting the order.');
        }

        return $result;
    }

    public function findByIdempotency(CloverConnection $connection, string $idempotencyKey): ?string
    {
        return $this->orders[$idempotencyKey]->id ?? null;
    }

    public function printOrder(CloverConnection $connection, string $cloverOrderId): CloverPrintResult
    {
        $this->prints++;
        if ($this->printStatus === 'unknown') {
            throw new CloverUncertainException('Printer result unknown.');
        }

        return new CloverPrintResult($this->printStatus, 'PRT'.$this->prints);
    }

    public function findCustomers(CloverConnection $connection, string $phoneE164): array
    {
        return $this->customers[$phoneE164] ?? [];
    }

    public function createCustomer(CloverConnection $connection, string $name, string $phoneE164): string
    {
        $this->customerCreates++;
        $id = 'CUS'.$this->customerCreates;
        $this->customers[$phoneE164][] = ['id' => $id, 'name' => $name, 'phone' => $phoneE164];

        return $id;
    }
}
