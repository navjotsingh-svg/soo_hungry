<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Merchant;
use App\Domain\Messaging\Interpretation;
use App\Support\Money;
use Carbon\CarbonImmutable;

final class CafeCartBuilder
{
    public function __construct(
        private readonly PickupQuoter $quoter,
        private readonly PickupWindow $windows,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, summary: string, ready: bool}
     */
    public function build(Merchant $merchant, Customer $customer, Interpretation $interpretation, CarbonImmutable $nowLocal): array
    {
        $location = $merchant->locations()->with('hours')->first();
        $lines = $this->refreshLines($interpretation->lines);
        $pickupAt = null;
        if ($location !== null && $interpretation->pickupTime !== null) {
            $pickupAt = $this->windows->resolve($location, $nowLocal, $interpretation->pickupTime, $interpretation->pickupTomorrow);
        }

        $quote = $this->quoter->quote($lines, $pickupAt !== null, filled($customer->name), $interpretation->modifierConflict);
        $questions = $quote->questions;
        if ($interpretation->pickupTime !== null && $pickupAt === null && $location !== null) {
            $questions[] = 'That pickup time is outside the café hours or the lead time. Next openings: '.implode(', ', $this->windows->suggestions($location, $nowLocal)).'.';
        }
        if ($interpretation->pickupTime === null && $location !== null) {
            $suggestions = $this->windows->suggestions($location, $nowLocal);
            if ($suggestions !== []) {
                $questions[] = 'Pickup times: '.implode(', ', $suggestions).'.';
            }
        }

        $ready = $questions === [] && $quote->ready;
        $summary = $ready
            ? $this->readySummary($lines, $pickupAt, $customer, $quote->totalCents)
            : implode(' ', array_unique($questions));

        return [
            'ready' => $ready,
            'summary' => $summary,
            'payload' => [
                'lines' => $lines,
                'ready' => $ready,
                'pickup_at' => $pickupAt?->toIso8601String(),
                'pickup_time' => $interpretation->pickupTime,
                'pickup_tomorrow' => $interpretation->pickupTomorrow,
                'quoted_subtotal_cents' => $quote->subtotalCents,
                'quoted_tax_cents' => $quote->taxCents,
                'quoted_total_cents' => $quote->totalCents,
                'questions' => array_values(array_unique($questions)),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function refreshLines(array $lines): array
    {
        $items = CatalogItem::query()->whereIn('id', collect($lines)->pluck('catalog_item_id'))->get()->keyBy('id');
        foreach ($lines as $index => $line) {
            $item = $items->get($line['catalog_item_id']);
            if ($item === null) {
                $lines[$index]['available'] = false;
                continue;
            }
            $lines[$index]['name'] = $item->name;
            $lines[$index]['clover_item_id'] = $item->clover_item_id;
            $lines[$index]['unit_price_cents'] = $item->price_cents;
            $lines[$index]['tax_rate_bps'] = $item->tax_rate_bps;
            $lines[$index]['available'] = $item->available;
            $lines[$index]['groups'] = $item->modifier_groups ?? [];
            $lines[$index]['missing_groups'] = $this->missing($lines[$index]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<string>
     */
    private function missing(array $line): array
    {
        $missing = [];
        foreach ($line['groups'] ?? [] as $group) {
            if (! ($group['required'] ?? false)) {
                continue;
            }
            $chosen = false;
            foreach ($line['modifiers'] ?? [] as $modifier) {
                if ($modifier['group_id'] === $group['id']) {
                    $chosen = true;
                }
            }
            if (! $chosen) {
                $missing[] = $group['name'];
            }
        }

        return $missing;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function readySummary(array $lines, ?CarbonImmutable $pickupAt, Customer $customer, int $total): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $modifiers = collect($line['modifiers'] ?? [])->pluck('name')->implode(', ');
            $parts[] = $line['quantity'].' '.$line['name'].($modifiers !== '' ? ' ('.$modifiers.')' : '');
        }
        $when = $pickupAt?->format('D g:i A') ?? 'a time still to choose';

        return implode('; ', $parts).'. Pickup '.$when.' for '.$customer->name.'. Total '.Money::cad($total).' including tax. Pay at pickup. Reply YES to send this to the café.';
    }
}
