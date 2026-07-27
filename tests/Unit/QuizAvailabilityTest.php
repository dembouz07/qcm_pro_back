<?php

namespace Tests\Unit;

use App\Models\Quiz;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class QuizAvailabilityTest extends TestCase
{
    public function test_a_published_quiz_without_schedule_is_immediately_open(): void
    {
        $quiz = new Quiz([
            'is_published' => true,
            'starts_at' => null,
            'ends_at' => null,
            'closed_at' => null,
        ]);

        $this->assertFalse($quiz->isLocked());
        $this->assertFalse($quiz->isClosed());
        $this->assertTrue($quiz->isOpen());
    }

    public function test_manual_closing_makes_an_unscheduled_quiz_unavailable(): void
    {
        $quiz = new Quiz();
        $quiz->setRawAttributes([
            'is_published' => true,
            'starts_at' => null,
            'ends_at' => null,
            'closed_at' => Carbon::now(),
        ]);

        $this->assertFalse($quiz->isLocked());
        $this->assertTrue($quiz->isClosed());
        $this->assertFalse($quiz->isOpen());
    }

    public function test_a_progressive_quiz_ignores_a_legacy_schedule(): void
    {
        $quiz = new Quiz();
        $quiz->setRawAttributes([
            'type' => 'progressive',
            'is_published' => true,
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->subDay(),
            'closed_at' => null,
        ]);

        $this->assertFalse($quiz->isLocked());
        $this->assertFalse($quiz->isClosed());
        $this->assertTrue($quiz->isOpen());
    }
}
