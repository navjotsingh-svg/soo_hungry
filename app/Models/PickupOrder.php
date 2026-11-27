<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickupOrder extends Model
{
    protected $fillable = [
        'merchant_id', 'location_id', 'customer_id', 'proposed_action_id', 'idempotency_key',
        'submission_status', 'dispatch_status', 'pickup_status', 'clover_order_id',
        'quoted_subtotal_cents', 'quoted_tax_cents', 'quoted_total_cents', 'pickup_name',
        'pickup_at', 'pay_at_pickup', 'submitted_payload', 'catalog_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_at' => 'datetime',
            'pay_at_pickup' => 'boolean',
            'submitted_payload' => 'array',
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ProposedAction::class, 'proposed_action_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PickupOrderLine::class);
    }

    public function printEvents(): HasMany
    {
        return $this->hasMany(PrintEvent::class);
    }
}
