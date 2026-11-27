<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use Carbon\CarbonImmutable;

/**
 * Each kitchen has one local cutoff for the following day's meals.
 * The timestamp that matters is the earliest message that clearly names the meal.
 * A request at or before that instant is timely. The customer then has the grace
 * window to confirm it. A first message after the cutoff is late immediately.
 */
final class CutoffClock
{
    public function cutoffAt(CarbonImmutable $serviceDate, string $cutoffTime): CarbonImmutable
    {
        $local = $serviceDate->timezone($serviceDate->timezone)->startOfDay();

        return CarbonImmutable::parse($local->toDateString().' '.$cutoffTime, $local->timezone)->subDay();
    }

    public function confirmUntil(CarbonImmutable $serviceDate, string $cutoffTime, int $graceMinutes): CarbonImmutable
    {
        return $this->cutoffAt($serviceDate, $cutoffTime)->addMinutes($graceMinutes);
    }

    public function eligibility(
        CarbonImmutable $identifiedAt,
        CarbonImmutable $confirmedAt,
        CarbonImmutable $serviceDate,
        string $cutoffTime,
        int $graceMinutes,
        bool $ownerApproved = false,
    ): string {
        if ($ownerApproved) {
            return 'owner_approved';
        }

        $zone = $serviceDate->timezone;
        $cutoff = $this->cutoffAt($serviceDate->timezone($zone), $cutoffTime);
        $identifiedAt = $identifiedAt->timezone($zone);
        $confirmedAt = $confirmedAt->timezone($zone);

        if ($identifiedAt->greaterThan($cutoff)) {
            return 'late';
        }

        return $confirmedAt->greaterThan($cutoff->addMinutes($graceMinutes)) ? 'late' : 'timely';
    }
}
