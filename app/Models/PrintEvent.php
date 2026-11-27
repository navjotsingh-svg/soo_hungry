<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'pickup_order_id', 'kind', 'clover_print_event_id', 'status', 'marked_copy', 'ticket', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'marked_copy' => 'boolean',
            'ticket' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PickupOrder::class, 'pickup_order_id');
    }
}
