<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItem extends Model
{
    protected $fillable = [
        'clover_connection_id', 'clover_item_id', 'name', 'price_cents', 'tax_rate_bps',
        'available', 'modifier_groups', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'modifier_groups' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CloverConnection::class, 'clover_connection_id');
    }
}
