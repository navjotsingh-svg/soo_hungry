<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CloverConnection;
use App\Models\PickupOrder;

final class CloverAtomicOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public function build(PickupOrder $order, CloverConnection $connection): array
    {
        return [
            'orderCart' => [
                'orderType' => ['id' => $connection->pickup_order_type_id],
                'title' => 'WA-'.$order->idempotency_key,
                'note' => 'Unpaid pickup. Pay at the counter. Ref '.$order->idempotency_key,
                'lineItems' => $order->lines->map(function ($line): array {
                    return [
                        'item' => ['id' => $line->clover_item_id],
                        'name' => $line->name,
                        'price' => $line->unit_price_cents,
                        'unitQty' => $line->quantity,
                        'printed' => false,
                        'exchanged' => false,
                        'refunded' => false,
                        'modifications' => collect($line->modifiers ?? [])->map(fn (array $modifier): array => [
                            'modifier' => ['id' => $modifier['option_id']],
                            'name' => $modifier['name'],
                            'amount' => $modifier['price_cents'],
                        ])->all(),
                    ];
                })->all(),
            ],
        ];
    }
}
