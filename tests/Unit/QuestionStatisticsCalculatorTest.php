<?php

namespace Tests\Unit;

use App\Services\QuestionStatisticsCalculator;
use PHPUnit\Framework\TestCase;

class QuestionStatisticsCalculatorTest extends TestCase
{
    public function test_unanswered_questions_are_excluded_from_wrong_rate_and_counted_separately(): void
    {
        $calculator = new QuestionStatisticsCalculator();

        $questions = [
            ['id' => 1, 'body' => 'Question une'],
            ['id' => 2, 'body' => 'Question deux'],
            ['id' => 3, 'body' => 'Question trois'],
        ];
        $answers = [
            ['question_id' => 1, 'choice_id' => 10, 'selected_choice_ids' => null, 'is_correct' => false],
            ['question_id' => 1, 'choice_id' => 11, 'selected_choice_ids' => null, 'is_correct' => true],
            ['question_id' => 1, 'choice_id' => null, 'selected_choice_ids' => [], 'is_correct' => false],
            ['question_id' => 2, 'choice_id' => null, 'selected_choice_ids' => '[20,21]', 'is_correct' => false],
        ];

        $stats = $calculator->calculate($questions, $answers, 3)->keyBy('id');

        $this->assertSame(2, $stats[1]['answered']);
        $this->assertSame(1, $stats[1]['wrong']);
        $this->assertSame(50, $stats[1]['wrong_rate']);
        $this->assertSame(1, $stats[1]['unanswered']);
        $this->assertSame(33, $stats[1]['unanswered_rate']);

        $this->assertSame(1, $stats[2]['answered']);
        $this->assertSame(100, $stats[2]['wrong_rate']);
        $this->assertSame(2, $stats[2]['unanswered']);
        $this->assertSame(67, $stats[2]['unanswered_rate']);

        $this->assertSame(0, $stats[3]['answered']);
        $this->assertSame(0, $stats[3]['wrong_rate']);
        $this->assertSame(3, $stats[3]['unanswered']);
        $this->assertSame(100, $stats[3]['unanswered_rate']);
    }
}
