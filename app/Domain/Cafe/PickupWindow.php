<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use Carbon\CarbonImmutable;
use App\Models\Location;

final class PickupWindow
{
    public function resolve(Location $location, CarbonImmutable $nowLocal, string $clockTime, bool $tomorrow): ?CarbonImmutable
    {
        $date = $tomorrow ? $nowLocal->addDay() : $nowLocal;
        $candidate = CarbonImmutable::parse($date->toDateString().' '.$clockTime, $nowLocal->timezone);

        if (! $tomorrow && $candidate->lessThanOrEqualTo($nowLocal->addMinutes((int) $location->lead_time_minutes))) {
            $candidate = $candidate->addDay();
        }

        return $this->allows($location, $candidate, $nowLocal) ? $candidate : null;
    }

    public function allows(Location $location, CarbonImmutable $pickupLocal, CarbonImmutable $nowLocal): bool
    {
        if ($pickupLocal->lessThanOrEqualTo($nowLocal->addMinutes((int) $location->lead_time_minutes))) {
            return false;
        }

        $hours = $location->hours->firstWhere('day_of_week', $pickupLocal->dayOfWeekIso);
        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return false;
        }

        $open = CarbonImmutable::parse($pickupLocal->toDateString().' '.substr((string) $hours->opens_at, 0, 5), $pickupLocal->timezone);
        $close = CarbonImmutable::parse($pickupLocal->toDateString().' '.substr((string) $hours->closes_at, 0, 5), $pickupLocal->timezone);

        return $pickupLocal->greaterThanOrEqualTo($open) && $pickupLocal->lessThan($close);
    }

    /**
     * @return list<string>
     */
    public function suggestions(Location $location, CarbonImmutable $nowLocal): array
    {
        $suggestions = [];
        $cursor = $nowLocal->addMinutes((int) $location->lead_time_minutes)->addMinutes(15 - ($nowLocal->minute % 15));

        for ($step = 0; $step < 96 && count($suggestions) < 4; $step++) {
            if ($this->allows($location, $cursor, $nowLocal)) {
                $suggestions[] = $cursor->format('D g:i A');
            }
            $cursor = $cursor->addMinutes(30);
        }

        return $suggestions;
    }
}
