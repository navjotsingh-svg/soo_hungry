<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

/**
 * Pilot phrase book for English and common Roman Punjabi.
 * Anything it cannot name clearly is a clarification or a staff handoff,
 * never a guessed subscription or a guessed order.
 */
final class PilotPhraseInterpreter
{
    /**
     * @param  list<array<string, mixed>>  $catalog
     */
    public function interpret(string $body, array $catalog = []): Interpretation
    {
        $text = mb_strtolower(trim($body));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = str_replace(['’', '`'], "'", $text);

        if (preg_match('/^(stop|unsubscribe|opt out|opt-out|cancel messages)$/', $text)) {
            return Interpretation::optOut();
        }

        if (preg_match('/^(yes|y|yeah|yep|ok|okay|confirm|confirmed|haan|han|ha+|ji haan|ji han|theek hai|theek|done)$/', $text)) {
            return Interpretation::confirmation();
        }

        if (preg_match('/^[1-9]$/', $text)) {
            return Interpretation::selection((int) $text);
        }

        if (preg_match('/\b(allerg|allergic)\b/', $text)) {
            return Interpretation::handoff('allergy');
        }

        if (preg_match('/\b(talk to (a )?(person|human|someone|staff)|real person|call me|agent)\b/', $text)) {
            return Interpretation::handoff('human');
        }

        if (preg_match('/\b(i paid|paid already|payment sent|e-transfer sent|etransfer sent|sent the e-transfer)\b/', $text)) {
            return Interpretation::paymentClaim();
        }

        if (preg_match('/\b(renew|renewal|recharge)\b/', $text)) {
            return Interpretation::renewal();
        }

        if (preg_match('/\b(feedback|complaint|refund)\b/', $text)) {
            return Interpretation::handoff('feedback');
        }

        if (preg_match('/\bcancel\b/', $text)) {
            return Interpretation::handoff('cancel');
        }

        $tiffin = $this->tiffinChange($text);
        if ($tiffin !== null) {
            return $tiffin;
        }

        $pickup = $this->pickup($text, $catalog);
        if ($pickup !== null) {
            return $pickup;
        }

        $punjabi = (bool) preg_match('/\b(ji|kal|bhej|bhejna|haan|han|theek|roti|tiffin|khana|nahi)\b/', $text);
        if ($punjabi || preg_match('/\b(lunch|dinner|tomorrow|meal)\b/', $text)) {
            return Interpretation::ambiguous($punjabi);
        }

        return Interpretation::unclear();
    }

    private function tiffinChange(string $text): ?Interpretation
    {
        $pause = (bool) preg_match('/\bpause\b/', $text);
        $resume = (bool) preg_match('/\b(resume|restart)\b/', $text) || (bool) preg_match('/phir se bhej/', $text);
        $openCue = (bool) preg_match('/\b(until i say|until further notice|open ended|open-ended|indefinitely)\b/', $text);

        $skipSlots = [];
        if (preg_match('/lunch\s+na\s+bhej/', $text) || (preg_match("/\b(skip|don't send|do not send)\b/", $text) && preg_match('/\blunch\b/', $text)) || preg_match('/\bno lunch\b/', $text)) {
            $skipSlots[] = 'lunch';
        }
        if (preg_match('/dinner\s+na\s+bhej/', $text) || (preg_match("/\b(skip|don't send|do not send)\b/", $text) && preg_match('/\bdinner\b/', $text)) || preg_match('/\bno dinner\b/', $text)) {
            $skipSlots[] = 'dinner';
        }
        if (preg_match("/\b(skip|don't send|do not send)\b/", $text) && ! preg_match('/\b(lunch|dinner)\b/', $text)) {
            $skipSlots[] = 'unspecified';
        }

        $keep = [];
        if (preg_match('/lunch\s+bhej\s+d/', $text) || preg_match('/\b(keep|send) lunch\b/', $text)) {
            $keep[] = 'lunch';
        }
        if (preg_match('/dinner\s+bhej\s+d/', $text) || preg_match('/\b(keep|send) dinner\b/', $text)) {
            $keep[] = 'dinner';
        }

        $skipSlots = array_values(array_unique($skipSlots));

        if (! $pause && ! $resume && $skipSlots === [] && $keep === []) {
            return null;
        }

        $when = $this->when($text);
        $openEnded = $openCue || ($pause && $when['type'] === 'unspecified');
        $portions = $this->portions($text);

        return new Interpretation(
            kind: 'tiffin_change',
            skipSlots: $skipSlots,
            keepSlots: $keep,
            when: $when,
            pause: $pause,
            resume: $resume,
            openEnded: $openEnded,
            portions: $portions,
        );
    }

    /**
     * @return array{type:string, day?:string, start?:string, end?:string}
     */
    private function when(string $text): array
    {
        if (preg_match('/\b(tomorrow|kal)\b/', $text)) {
            return ['type' => 'tomorrow'];
        }
        if (preg_match('/\b(today|aaj|ajj)\b/', $text)) {
            return ['type' => 'today'];
        }

        $found = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            if (preg_match('/\b'.$day.'\b/', $text)) {
                $found[] = $day;
            }
        }

        if (count($found) >= 2) {
            return ['type' => 'range', 'start' => $found[0], 'end' => $found[1]];
        }
        if (count($found) === 1) {
            return ['type' => 'weekday', 'day' => $found[0]];
        }

        return ['type' => 'unspecified'];
    }

    private function portions(string $text): ?int
    {
        if (preg_match('/\b(\d+)\s+(lunch|dinner|portion)/', $text, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/\b(one|two|three)\s+(lunch|dinner|portion)/', $text, $match)) {
            return match ($match[1]) {
                'two' => 2,
                'three' => 3,
                default => 1,
            };
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     */
    private function pickup(string $text, array $catalog): ?Interpretation
    {
        $items = $catalog;
        usort($items, fn (array $a, array $b): int => strlen((string) $b['name']) <=> strlen((string) $a['name']));

        $hits = [];
        $cursor = $text;
        foreach ($items as $item) {
            $name = mb_strtolower((string) $item['name']);
            $pattern = '/\b'.preg_quote($name, '/').'s?\b/';
            if (! preg_match($pattern, $cursor, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $pos = (int) $match[0][1];
            $before = substr($cursor, max(0, $pos - 24), min(24, $pos));
            $qty = 1;
            if (preg_match('/(\d+|one|two|three|four|five|a|an)\s*$/', trim($before), $qtyMatch)) {
                $qty = $this->number($qtyMatch[1]);
            }
            $cursor = substr($cursor, 0, $pos).substr($cursor, $pos + strlen($match[0][0]));
            $hits[] = ['item' => $item, 'quantity' => max(1, $qty)];
        }

        if ($hits === []) {
            return null;
        }

        $distinct = [];
        foreach ($hits as $hit) {
            for ($i = 0; $i < $hit['quantity']; $i++) {
                $distinct[] = [
                    'catalog_item_id' => $hit['item']['id'],
                    'clover_item_id' => $hit['item']['clover_item_id'],
                    'name' => $hit['item']['name'],
                    'quantity' => 1,
                    'unit_price_cents' => $hit['item']['price_cents'],
                    'tax_rate_bps' => $hit['item']['tax_rate_bps'],
                    'available' => $hit['item']['available'],
                    'modifiers' => [],
                    'missing_groups' => [],
                    'groups' => $hit['item']['modifier_groups'] ?? [],
                ];
            }
        }

        $conflict = false;
        foreach ($hits as $hit) {
            $mentioned = [];
            foreach ($hit['item']['modifier_groups'] ?? [] as $group) {
                foreach ($group['options'] as $option) {
                    $optionName = mb_strtolower((string) $option['name']);
                    if (preg_match('/(?:(\d+|one|two|three|four|a|an)\s+)?'.preg_quote($optionName, '/').'\b/', $text, $optionMatch)) {
                        $qty = isset($optionMatch[1]) && $optionMatch[1] !== '' ? $this->number($optionMatch[1]) : null;
                        $mentioned[$group['id']][] = ['group' => $group, 'option' => $option, 'quantity' => $qty];
                    }
                }
            }

            $itemLines = array_keys(array_filter($distinct, fn (array $line): bool => $line['catalog_item_id'] === $hit['item']['id']));
            foreach ($mentioned as $groupId => $options) {
                if (count($options) > 1 && $hit['quantity'] === 1) {
                    $conflict = true;
                    continue;
                }
                $assignments = $this->modifierAssignments($options, count($itemLines));
                $offset = 0;
                foreach ($distinct as $index => $line) {
                    if ($line['catalog_item_id'] !== $hit['item']['id']) {
                        continue;
                    }
                    if (! isset($assignments[$offset])) {
                        $offset++;
                        continue;
                    }
                    $choice = $assignments[$offset];
                    $distinct[$index]['modifiers'][] = [
                        'group_id' => $choice['group']['id'],
                        'group_name' => $choice['group']['name'],
                        'option_id' => $choice['option']['id'],
                        'name' => $choice['option']['name'],
                        'price_cents' => $choice['option']['price_cents'],
                    ];
                    $offset++;
                }
            }
        }

        foreach ($distinct as $index => $line) {
            $missing = [];
            foreach ($line['groups'] as $group) {
                if (! ($group['required'] ?? false)) {
                    continue;
                }
                $chosen = false;
                foreach ($line['modifiers'] as $modifier) {
                    if ($modifier['group_id'] === $group['id']) {
                        $chosen = true;
                    }
                }
                if (! $chosen) {
                    $missing[] = $group['name'];
                }
            }
            $distinct[$index]['missing_groups'] = $missing;
            unset($distinct[$index]['groups']);
        }

        return new Interpretation(
            kind: 'pickup',
            lines: $distinct,
            pickupTime: $this->clockTime($text),
            pickupTomorrow: (bool) preg_match('/\btomorrow\b/', $text),
            modifierConflict: $conflict,
        );
    }

    /**
     * @param  list<array{group:array<string,mixed>, option:array<string,mixed>, quantity:?int}>  $options
     * @return list<array{group:array<string,mixed>, option:array<string,mixed>}>
     */
    private function modifierAssignments(array $options, int $lineCount): array
    {
        $assigned = [];
        if (count($options) === 1 && ($options[0]['quantity'] ?? null) !== null) {
            $copies = min($lineCount, (int) $options[0]['quantity']);
            for ($i = 0; $i < $copies; $i++) {
                $assigned[] = $options[0];
            }

            return $assigned;
        }

        if (count($options) > 1 && count($options) === $lineCount) {
            return $options;
        }

        if (count($options) === 1) {
            return [$options[0]];
        }

        return $options;
    }

    private function clockTime(string $text): ?string
    {
        if (! preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $text, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0;
        if ($match[3] === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($match[3] === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function number(string $word): int
    {
        return match ($word) {
            'a', 'an', 'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            default => (int) $word,
        };
    }
}
