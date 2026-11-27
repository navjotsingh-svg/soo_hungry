<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupOrderLine extends Model
{
    protected $fillable = [
        'pickup_order_id', 'catalog_item_id', 'clover_item_id', 'name', 'quantity',
        'modifiers', 'unit_price_cents', 'line_total_cents',
    ];

    protected function casts(): array
    {
        return ['modifiers' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PickupOrder::class, 'pickup_order_id');
    }
}
