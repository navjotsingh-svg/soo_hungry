<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloverConnection extends Model
{
    protected $fillable = [
        'merchant_id', 'location_id', 'clover_merchant_id', 'access_token', 'environment',
        'pickup_order_type_id', 'default_printer_id', 'print_mode', 'ordering_paused',
        'can_create_customers', 'catalog_synced_at', 'sync_max_age_minutes',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'ordering_paused' => 'boolean',
            'can_create_customers' => 'boolean',
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function catalogIsFresh(): bool
    {
        if ($this->catalog_synced_at === null) {
            return false;
        }

        return $this->catalog_synced_at->greaterThan(now()->subMinutes((int) $this->sync_max_age_minutes));
    }
}
