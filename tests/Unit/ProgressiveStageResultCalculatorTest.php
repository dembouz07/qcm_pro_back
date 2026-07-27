<?php

namespace Tests\Unit;

use App\Services\ProgressiveStageResultCalculator;
use PHPUnit\Framework\TestCase;

class ProgressiveStageResultCalculatorTest extends TestCase
{
    private ProgressiveStageResultCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProgressiveStageResultCalculator();
    }

    public function test_five_yes_answers_keep_the_participant_at_the_current_stage_when_blocking_is_enabled(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 5, 2 => 0, 3 => 0], 5, true);

        $this->assertSame(1, $stage);
    }

    public function test_four_yes_answers_advance_until_a_stage_reaches_five(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 4, 2 => 5, 3 => 0], 5, true);

        $this->assertSame(2, $stage);
    }

    public function test_reaching_the_threshold_does_not_block_progression_when_requirement_is_disabled(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 5, 2 => 6, 3 => 5], 5, false);

        $this->assertSame(3, $stage);
    }

    public function test_an_unanswered_future_stage_is_not_considered_reached(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 5, 2 => 5], 5, false);

        $this->assertSame(2, $stage);
    }
}
