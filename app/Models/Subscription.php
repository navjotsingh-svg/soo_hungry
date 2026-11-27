<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanKind;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'merchant_id', 'customer_id', 'status', 'plan_kind', 'service_days', 'day_meals', 'breakfast_portions', 'lunch_portions',
        'dinner_portions', 'meal_type', 'diet', 'plan_price_cents', 'pack_size', 'pack_sequence', 'cycle_days',
        'start_date', 'period_end', 'portion_credits_remaining', 'renewal_portion_threshold',
        'renewal_days_threshold', 'address', 'packing_notes', 'terms_summary', 'customer_confirmed_at',
        'owner_approved_at', 'owner_approved_by', 'pause_open_ended', 'paused_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'plan_kind' => PlanKind::class,
            'service_days' => 'array',
            'day_meals' => 'array',
            'start_date' => 'date',
            'period_end' => 'date',
            'customer_confirmed_at' => 'datetime',
            'owner_approved_at' => 'datetime',
            'pause_open_ended' => 'boolean',
            'paused_until' => 'date',
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

    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function portionsFor(string $slot): int
    {
        return match ($slot) {
            'breakfast' => (int) $this->breakfast_portions,
            'lunch' => (int) $this->lunch_portions,
            'dinner' => (int) $this->dinner_portions,
            default => 0,
        };
    }

    public function serves(int $day, string $slot): bool
    {
        if (is_array($this->day_meals)) {
            $slots = $this->day_meals[(string) $day] ?? $this->day_meals[$day] ?? [];

            return in_array($slot, $slots, true);
        }

        return in_array($day, array_map('intval', $this->service_days ?? []), true)
            && $this->portionsFor($slot) > 0;
    }

    /**
     * @param  array<mixed>  $input
     * @return array<string, list<string>>
     */
    public static function dayMealsFromInput(array $input): array
    {
        $clean = [];
        foreach ($input as $day => $chosen) {
            $day = (int) $day;
            if ($day < 1 || $day > 7) {
                continue;
            }
            $chosen = array_values(array_unique(array_intersect(
                array_map('strval', (array) $chosen),
                ['breakfast', 'lunch', 'dinner'],
            )));
            if ($chosen !== []) {
                $clean[(string) $day] = $chosen;
            }
        }
        ksort($clean);

        return $clean;
    }

    /**
     * @param  array<string, list<string>>  $dayMeals
     * @return array{days: list<int>, breakfast: int, lunch: int, dinner: int}
     */
    public static function portionsForDayMeals(array $dayMeals, int $breakfast, int $lunch, int $dinner): array
    {
        $used = [];
        foreach ($dayMeals as $slots) {
            foreach ($slots as $slot) {
                $used[$slot] = true;
            }
        }
        $days = array_map('intval', array_keys($dayMeals));
        sort($days);

        return [
            'days' => $days,
            'breakfast' => isset($used['breakfast']) ? max(1, $breakfast) : 0,
            'lunch' => isset($used['lunch']) ? max(1, $lunch) : 0,
            'dinner' => isset($used['dinner']) ? max(1, $dinner) : 0,
        ];
    }

    public function weekSummary(): string
    {
        if (! is_array($this->day_meals)) {
            return '';
        }

        $groups = [];
        foreach (TiffinMenuItem::DAYS as $day => $name) {
            $slots = $this->day_meals[(string) $day] ?? [];
            if ($slots === []) {
                continue;
            }
            $key = implode(',', $slots);
            $groups[$key]['days'][] = substr($name, 0, 3);
            $groups[$key]['slots'] = array_map(fn (string $slot): string => TiffinMenuItem::SLOTS[$slot] ?? $slot, $slots);
        }

        $parts = [];
        foreach ($groups as $group) {
            $parts[] = implode(', ', $group['days']).' '.implode(', ', $group['slots']);
        }

        return implode(' · ', $parts);
    }
}
