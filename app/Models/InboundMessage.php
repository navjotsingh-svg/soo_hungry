<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundMessage extends Model
{
    protected $fillable = [
        'merchant_id', 'conversation_id', 'customer_id', 'webhook_event_id', 'provider_message_id',
        'wa_timestamp', 'message_type', 'body', 'raw', 'draft', 'result', 'processing_status', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'wa_timestamp' => 'datetime',
            'raw' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
