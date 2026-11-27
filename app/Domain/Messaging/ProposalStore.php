<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\Module;
use App\Models\InboundMessage;
use App\Models\ProposedAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProposalStore
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function open(
        InboundMessage $inbound,
        Module $module,
        ActionType $type,
        array $payload,
        string $summary,
        ?CarbonImmutable $expires,
        bool $staffOnly = false,
    ): ProposedAction {
        return DB::transaction(function () use ($inbound, $module, $type, $payload, $summary, $expires, $staffOnly): ProposedAction {
            $prior = ProposedAction::query()
                ->where('customer_id', $inbound->customer_id)
                ->where('module', $module)
                ->where('status', ActionStatus::Pending)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($prior !== null) {
                $prior->update(['status' => ActionStatus::Superseded]);
            }

            return ProposedAction::create([
                'public_id' => (string) Str::uuid(),
                'merchant_id' => $inbound->merchant_id,
                'customer_id' => $inbound->customer_id,
                'conversation_id' => $inbound->conversation_id,
                'inbound_message_id' => $inbound->id,
                'module' => $module,
                'action_type' => $type,
                'revision' => ($prior->revision ?? 0) + 1,
                'supersedes_id' => $prior?->id,
                'status' => $staffOnly ? ActionStatus::StaffReview : ActionStatus::Pending,
                'payload' => $payload,
                'summary' => $summary,
                'expires_at' => $expires,
            ]);
        });
    }
}
