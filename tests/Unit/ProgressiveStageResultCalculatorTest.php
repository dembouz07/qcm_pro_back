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

    public function test_failure_stops_progression_when_each_stage_is_required(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 0, 2 => 2, 3 => 2], 1, true);

        $this->assertSame(1, $stage);
    }

    public function test_successful_stages_progress_until_the_first_failure(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 2, 2 => 0, 3 => 2], 1, true);

        $this->assertSame(1, $stage);
    }

    public function test_failure_does_not_block_progression_when_requirement_is_disabled(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 0, 2 => 1, 3 => 0], 1, false);

        $this->assertSame(3, $stage);
    }

    public function test_an_unanswered_future_stage_is_not_considered_reached(): void
    {
        $stage = $this->calculator->calculate([1, 2, 3], [1 => 0, 2 => 0], 1, false);

        $this->assertSame(2, $stage);
    }
}
