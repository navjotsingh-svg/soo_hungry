<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CloverConnection;

interface CloverGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkout(CloverConnection $connection, array $payload, int $quotedTotalCents): CloverCheckout;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrder(CloverConnection $connection, array $payload, string $idempotencyKey): CloverOrderResult;

    public function findByIdempotency(CloverConnection $connection, string $idempotencyKey): ?string;

    public function printOrder(CloverConnection $connection, string $cloverOrderId): CloverPrintResult;

    /**
     * @return list<array{id:string, name:string, phone:string}>
     */
    public function findCustomers(CloverConnection $connection, string $phoneE164): array;

    public function createCustomer(CloverConnection $connection, string $name, string $phoneE164): string;
}
