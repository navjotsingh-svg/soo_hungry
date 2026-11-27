<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\IntegrationAlert;
use Illuminate\Database\Eloquent\Model;

final class Alerts
{
    public function raise(int $merchantId, string $integration, string $message, ?Model $subject = null): IntegrationAlert
    {
        return IntegrationAlert::create([
            'merchant_id' => $merchantId,
            'integration' => $integration,
            'severity' => 'warning',
            'message' => $message,
            'status' => 'open',
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'created_at' => now(),
        ]);
    }
}
