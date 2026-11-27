<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

final class PickupQuoter
{
    /**
     * Tax is added from the synced rate and the total shown to the customer includes it.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function quote(array $lines, bool $timeSet, bool $nameSet, bool $conflict): PickupQuote
    {
        $questions = [];
        if ($conflict) {
            $questions[] = 'Those options conflict. Tell me which one you want on each item.';
        }

        foreach ($lines as $line) {
            if (! ($line['available'] ?? true)) {
                $questions[] = $line['name'].' is sold out.';
            }
            foreach ($line['missing_groups'] ?? [] as $group) {
                $questions[] = 'Which '.$group.' for '.$line['name'].'?';
            }
        }

        if (! $timeSet) {
            $questions[] = 'What pickup time would you like?';
        }
        if (! $nameSet) {
            $questions[] = 'What name should the café put on the order?';
        }

        $questions = array_values(array_unique($questions));
        $ready = $questions === [] && $lines !== [];
        $subtotal = 0;
        $tax = 0;

        if ($ready) {
            foreach ($lines as $line) {
                $modifiers = 0;
                foreach ($line['modifiers'] ?? [] as $modifier) {
                    $modifiers += (int) $modifier['price_cents'];
                }
                $lineAmount = ((int) $line['unit_price_cents'] + $modifiers) * (int) $line['quantity'];
                $subtotal += $lineAmount;
                $tax += (int) round($lineAmount * ((int) $line['tax_rate_bps']) / 10000);
            }
        }

        return new PickupQuote($ready, $questions, $subtotal, $tax, $subtotal + $tax, $lines);
    }
}
