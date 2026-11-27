<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'merchant_id', 'customer_id', 'whatsapp_account_id', 'status',
        'taken_over_by', 'taken_over_at', 'last_customer_message_at',
    ];

    protected function casts(): array
    {
        return [
            'taken_over_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(InboundMessage::class)->latestOfMany('wa_timestamp');
    }

    public function outbound(): HasMany
    {
        return $this->hasMany(OutboundNotification::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(StaffHandoff::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ProposedAction::class);
    }

    public function automated(): bool
    {
        return $this->status === 'automated';
    }
}
