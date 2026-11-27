<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposedAction extends Model
{
    protected $fillable = [
        'public_id', 'merchant_id', 'customer_id', 'conversation_id', 'inbound_message_id',
        'module', 'action_type', 'revision', 'supersedes_id', 'status', 'payload', 'summary',
        'expires_at', 'confirmed_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'module' => Module::class,
            'action_type' => ActionType::class,
            'status' => ActionStatus::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'applied_at' => 'datetime',
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
