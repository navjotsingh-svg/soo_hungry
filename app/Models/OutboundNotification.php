<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundNotification extends Model
{
    protected $fillable = [
        'merchant_id', 'customer_id', 'conversation_id', 'proposed_action_id', 'kind', 'template_name',
        'body', 'is_proactive', 'source', 'status', 'provider_message_id', 'failure_reason',
        'idempotency_key', 'attempts', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_proactive' => 'boolean',
            'sent_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
