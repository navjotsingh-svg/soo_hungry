<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Messaging\PilotPhraseInterpreter;
use App\Domain\Tiffin\CoveragePlanner;
use PHPUnit\Framework\TestCase;

class PilotRulesTest extends TestCase
{
    public function test_the_roman_punjabi_demo_skips_lunch_and_keeps_dinner(): void
    {
        $interpretation = (new PilotPhraseInterpreter())->interpret('Kal lunch na bhejna ji, dinner bhej dena.');

        $this->assertSame('tiffin_change', $interpretation->kind);
        $this->assertSame(['lunch'], $interpretation->skipSlots);
        $this->assertSame(['dinner'], $interpretation->keepSlots);
        $this->assertSame('tomorrow', $interpretation->when['type']);
    }

    public function test_an_ambiguous_roman_punjabi_line_is_not_a_change(): void
    {
        $interpretation = (new PilotPhraseInterpreter())->interpret('kal dekh lo ji');

        $this->assertSame('ambiguous', $interpretation->kind);
        $this->assertTrue($interpretation->ambiguousPunjabi);
    }

    public function test_two_lattes_become_two_lines_and_one_modifier(): void
    {
        $interpretation = (new PilotPhraseInterpreter())->interpret('two lattes, one oat', [[
            'id' => 1,
            'clover_item_id' => 'LATTE',
            'name' => 'Latte',
            'price_cents' => 450,
            'tax_rate_bps' => 500,
            'available' => true,
            'modifier_groups' => [
                ['id' => 'milk', 'name' => 'Milk', 'required' => true, 'options' => [
                    ['id' => 'oat', 'name' => 'Oat', 'price_cents' => 80],
                    ['id' => 'dairy', 'name' => 'Dairy', 'price_cents' => 0],
                ]],
            ],
        ]]);

        $this->assertCount(2, $interpretation->lines);
        $this->assertSame('Oat', $interpretation->lines[0]['modifiers'][0]['name']);
        $this->assertSame([], $interpretation->lines[1]['modifiers']);
        $this->assertContains('Milk', $interpretation->lines[1]['missing_groups']);
    }

    public function test_skipping_tuesday_moves_the_last_covered_day_to_thursday(): void
    {
        $last = (new CoveragePlanner())->lastCovered(2, [
            ['date' => '2026-11-23', 'quantity' => 1, 'status' => 'fulfilled'],
            ['date' => '2026-11-24', 'quantity' => 1, 'status' => 'skipped'],
            ['date' => '2026-11-25', 'quantity' => 1, 'status' => 'scheduled'],
            ['date' => '2026-11-26', 'quantity' => 1, 'status' => 'scheduled'],
            ['date' => '2026-11-27', 'quantity' => 1, 'status' => 'scheduled'],
        ], '2026-11-23');

        $this->assertSame('2026-11-26', $last);
    }
}
