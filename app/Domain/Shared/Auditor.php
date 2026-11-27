<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

final class Auditor
{
    public function record(int $merchantId, ?Model $actor, string $action, ?Model $subject, string $source, array $metadata = []): void
    {
        AuditEvent::create([
            'merchant_id' => $merchantId,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'source' => $source,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
