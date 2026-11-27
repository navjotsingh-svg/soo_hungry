<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPermission extends Model
{
    protected $fillable = ['merchant_id', 'customer_id', 'proactive_allowed', 'opted_out_at', 'source'];

    protected function casts(): array
    {
        return [
            'proactive_allowed' => 'boolean',
            'opted_out_at' => 'datetime',
        ];
    }

    public function allowsProactive(): bool
    {
        return $this->proactive_allowed && $this->opted_out_at === null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
