<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalPrompt extends Model
{
    protected $fillable = [
        'merchant_id', 'subscription_id', 'cycle_key', 'status', 'last_covered_date',
        'outbound_notification_id', 'bill_id', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['last_covered_date' => 'date'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
