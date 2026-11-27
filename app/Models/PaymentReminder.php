<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    protected $fillable = [
        'merchant_id', 'bill_id', 'customer_id', 'due_on', 'status', 'outbound_notification_id',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(OutboundNotification::class, 'outbound_notification_id');
    }
}
