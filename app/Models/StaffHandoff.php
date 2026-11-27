<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffHandoff extends Model
{
    protected $fillable = [
        'merchant_id', 'conversation_id', 'inbound_message_id', 'proposed_action_id',
        'reason', 'draft_interpretation', 'status', 'assigned_to',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ProposedAction::class, 'proposed_action_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id');
    }
}
