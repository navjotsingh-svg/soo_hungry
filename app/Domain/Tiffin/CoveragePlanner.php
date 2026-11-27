<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

final class CoveragePlanner
{
    /**
     * Walk future portions that still consume a credit. Skips and pauses keep
     * the credit, so the last covered delivery moves to a later service day.
     *
     * @param  list<array{date:string, quantity:int, status:string}>  $entries
     */
    public function lastCovered(int $credits, array $entries, string $fromDate): ?string
    {
        $last = null;

        foreach ($entries as $entry) {
            if ($entry['date'] < $fromDate) {
                continue;
            }
            if (in_array($entry['status'], ['skipped', 'paused', 'cancelled', 'fulfilled'], true)) {
                continue;
            }
            if ($credits <= 0) {
                break;
            }

            $use = min($entry['quantity'], $credits);
            if ($use < 1) {
                continue;
            }

            $credits -= $use;
            $last = $entry['date'];
        }

        return $last;
    }
}
