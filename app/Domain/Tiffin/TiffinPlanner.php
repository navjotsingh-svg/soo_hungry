<?php

declare(strict_types=1);

namespace App\Domain\Tiffin;

use App\Enums\PlanKind;
use App\Models\Subscription;
use App\Domain\Messaging\Interpretation;
use Carbon\CarbonImmutable;

final class TiffinPlanner
{
    public function __construct(private readonly CutoffClock $clock) {}

    public function plan(
        Subscription $subscription,
        Interpretation $interpretation,
        CarbonImmutable $identifiedAt,
    ): TiffinPlan {
        $zone = $identifiedAt->timezone;
        $identifiedAt = $identifiedAt->timezone($zone);

        if ($interpretation->openEnded && $interpretation->pause) {
            return $this->openPause($subscription, $identifiedAt);
        }

        if ($interpretation->resume) {
            $dates = $this->dates($interpretation, $identifiedAt);
            if ($dates === []) {
                return $this->ask('Which day should the meals start again?');
            }
            $restart = $dates[0];
            $eligibility = $this->clock->eligibility(
                $identifiedAt,
                $identifiedAt,
                $restart,
                $subscription->merchant->cutoffTime(),
                $subscription->merchant->graceMinutes(),
            );
            $meal = [
                'effect' => 'resume',
                'date' => $restart->toDateString(),
                'eligibility' => $eligibility,
            ];
            if ($eligibility === 'late') {
                return new TiffinPlan(null, [], [$meal], ['A restart after the cutoff needs the owner.'], null, 'That restart is after the cutoff. The owner needs to approve it before meals start again.', resume: true, resumeDate: $restart->toDateString());
            }

            return new TiffinPlan(null, [$meal], [], [], $this->clock->confirmUntil($restart, $subscription->merchant->cutoffTime(), $subscription->merchant->graceMinutes())->toIso8601String(), 'Restart on '.$restart->format('l, M j').'. Reply YES to confirm.', resume: true, resumeDate: $restart->toDateString());
        }

        if (in_array('unspecified', $interpretation->skipSlots, true) && ! $interpretation->pause) {
            return $this->ask('Which meal should I change, lunch or dinner?');
        }

        $dates = $this->dates($interpretation, $identifiedAt);
        if ($dates === [] && ! $interpretation->pause) {
            return $this->ask('Which day should I change?');
        }
        if ($dates === []) {
            return $this->ask('Which dates should I pause?');
        }

        $slots = $interpretation->pause
            ? array_values(array_filter(['lunch', 'dinner'], fn (string $slot): bool => $subscription->portionsFor($slot) > 0))
            : array_values(array_filter($interpretation->skipSlots, fn (string $slot): bool => $slot !== 'unspecified'));

        if ($slots === []) {
            return $this->ask('Which meal should I change, lunch or dinner?');
        }

        $apply = [];
        $late = [];
        $notes = [];
        foreach ($interpretation->keepSlots as $slot) {
            $notes[] = ucfirst($slot).' stays scheduled.';
        }

        $cutoff = $subscription->merchant->cutoffTime();
        $grace = $subscription->merchant->graceMinutes();

        foreach ($dates as $date) {
            if (! in_array($date->dayOfWeekIso, $subscription->service_days, true)) {
                continue;
            }
            foreach ($slots as $slot) {
                if (! $subscription->serves($date->dayOfWeekIso, $slot)) {
                    continue;
                }
                $configured = $subscription->portionsFor($slot);
                if ($configured < 1) {
                    continue;
                }
                $quantity = $interpretation->portions ?? $configured;
                if ($interpretation->portions === null && $configured > 1 && ! $interpretation->pause) {
                    return $this->ask('You have '.$configured.' '.$slot.' portions. How many should I skip?');
                }
                if ($quantity > $configured) {
                    return $this->ask('This household has '.$configured.' '.$slot.' portions.');
                }

                $eligibility = $this->clock->eligibility($identifiedAt, $identifiedAt, $date, $cutoff, $grace);
                $row = [
                    'effect' => $interpretation->pause ? 'pause' : 'skip',
                    'date' => $date->toDateString(),
                    'slot' => $slot,
                    'quantity' => $quantity,
                    'eligibility' => $eligibility,
                ];
                if ($eligibility === 'late') {
                    $late[] = $row;
                } else {
                    $apply[] = $row;
                }
            }
        }

        if ($apply === [] && $late === []) {
            return $this->ask('I could not find that meal on the plan.');
        }

        $expires = null;
        foreach ($apply as $row) {
            $until = $this->clock->confirmUntil(CarbonImmutable::parse($row['date'], $zone), $cutoff, $grace);
            $expires = $expires === null || $until->lt($expires) ? $until : $expires;
        }

        $summary = $this->summary($subscription, $apply, $late, $notes, $expires, $dates);
        $next = null;
        if ($interpretation->pause && $dates !== []) {
            $next = $this->nextServiceDay($subscription, $dates[array_key_last($dates)]);
            if ($next !== null) {
                $summary .= ' Next scheduled day is '.$next->format('l, M j').'.';
                $notes[] = 'Next scheduled day is '.$next->format('l, M j').'.';
            }
        }

        return new TiffinPlan(null, $apply, $late, $notes, $expires?->toIso8601String(), trim($summary));
    }

    private function openPause(Subscription $subscription, CarbonImmutable $identifiedAt): TiffinPlan
    {
        $cutoff = $subscription->merchant->cutoffTime();
        $grace = $subscription->merchant->graceMinutes();
        $apply = [];
        $late = [];
        $entries = $subscription->scheduleEntries()
            ->whereDate('service_date', '>', $identifiedAt->toDateString())
            ->where('status', 'scheduled')
            ->orderBy('service_date')
            ->get();

        foreach ($entries as $entry) {
            $date = CarbonImmutable::parse($entry->service_date->toDateString(), $identifiedAt->timezone);
            $eligibility = $this->clock->eligibility($identifiedAt, $identifiedAt, $date, $cutoff, $grace);
            $row = [
                'effect' => 'pause',
                'date' => $date->toDateString(),
                'slot' => $entry->slot,
                'quantity' => (int) $entry->quantity,
                'eligibility' => $eligibility,
            ];
            if ($eligibility === 'late') {
                $late[] = $row;
            } else {
                $apply[] = $row;
            }
        }

        $apply[] = ['effect' => 'open_pause', 'from' => $identifiedAt->toDateString()];
        $expires = null;
        foreach ($apply as $row) {
            if (! isset($row['date'])) {
                continue;
            }
            $until = $this->clock->confirmUntil(CarbonImmutable::parse($row['date'], $identifiedAt->timezone), $cutoff, $grace);
            $expires = $expires === null || $until->lt($expires) ? $until : $expires;
        }

        $summary = 'Paused until you confirm a restart. Meal credits stay on the plan.';
        if ($late !== []) {
            $lateLabels = array_map(
                fn (array $row): string => CarbonImmutable::parse($row['date'])->format('l').' '.$row['slot'],
                $late,
            );
            $summary .= ' '.implode(', ', $lateLabels).' is past the cutoff and needs owner approval.';
        }
        if ($expires !== null) {
            $summary .= ' Reply YES by '.$expires->format('g:i A').'.';
        }

        return new TiffinPlan(null, $apply, $late, ['Paused until you confirm a restart.'], $expires?->toIso8601String(), trim($summary), openEnded: true);
    }

    /**
     * @param  list<array<string, mixed>>  $apply
     * @param  list<array<string, mixed>>  $late
     * @param  list<string>  $notes
     * @param  list<CarbonImmutable>  $dates
     */
    private function summary(Subscription $subscription, array $apply, array $late, array $notes, ?CarbonImmutable $expires, array $dates): string
    {
        $parts = [];
        foreach ($apply as $row) {
            if (($row['effect'] ?? '') === 'open_pause') {
                continue;
            }
            $day = CarbonImmutable::parse($row['date'])->format('l');
            $parts[] = $day.' '.$row['slot'].', '.$row['quantity'].' portion'.($row['quantity'] === 1 ? '' : 's');
        }

        if ($parts !== []) {
            $credit = $subscription->plan_kind === PlanKind::PrepaidPack
                ? 'Keeps '.array_sum(array_column($apply, 'quantity')).' meal credit.'
                : 'The plan fee and period end stay the same.';
            $parts[] = $credit;
        }

        foreach ($notes as $note) {
            $parts[] = $note;
        }

        foreach ($late as $row) {
            $day = CarbonImmutable::parse($row['date'])->format('l');
            $parts[] = $day.' '.$row['slot'].' is past the cutoff and needs owner approval.';
        }

        if ($apply === [] && $late !== []) {
            return 'That change is after the '.$subscription->merchant->cutoffTime().' cutoff. The owner has to approve it. Nothing changes until then.';
        }

        if ($expires !== null) {
            $parts[] = 'Reply YES by '.$expires->format('g:i A').'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function dates(Interpretation $interpretation, CarbonImmutable $identifiedAt): array
    {
        $start = $identifiedAt->startOfDay();

        return match ($interpretation->when['type']) {
            'tomorrow' => [$start->addDay()],
            'today' => [$start],
            'weekday' => [$this->onOrAfter($start, $this->iso((string) $interpretation->when['day']))],
            'range' => $this->span($start, (string) $interpretation->when['start'], (string) $interpretation->when['end']),
            default => [],
        };
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function span(CarbonImmutable $from, string $startDay, string $endDay): array
    {
        $start = $this->onOrAfter($from, $this->iso($startDay));
        $end = $this->onOrAfter($start, $this->iso($endDay));
        $dates = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function onOrAfter(CarbonImmutable $from, int $iso): CarbonImmutable
    {
        $cursor = $from->startOfDay();
        while ($cursor->dayOfWeekIso !== $iso) {
            $cursor = $cursor->addDay();
        }

        return $cursor;
    }

    private function nextServiceDay(Subscription $subscription, CarbonImmutable $after): ?CarbonImmutable
    {
        $cursor = $after->addDay();
        for ($i = 0; $i < 14; $i++) {
            if (in_array($cursor->dayOfWeekIso, $subscription->service_days, true)) {
                return $cursor;
            }
            $cursor = $cursor->addDay();
        }

        return null;
    }

    private function iso(string $day): int
    {
        return match ($day) {
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => 7,
        };
    }

    private function ask(string $question): TiffinPlan
    {
        return new TiffinPlan($question, [], [], [], null, $question);
    }
}
