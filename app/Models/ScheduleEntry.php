<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEntry extends Model
{
    protected $fillable = [
        'merchant_id', 'subscription_id', 'customer_id', 'service_date', 'slot', 'planned_quantity',
        'quantity', 'skipped_quantity', 'meal_type', 'diet', 'status', 'credit_treatment', 'proposed_action_id',
        'authorized_late_by', 'fulfilled_at', 'fulfillment_key',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
