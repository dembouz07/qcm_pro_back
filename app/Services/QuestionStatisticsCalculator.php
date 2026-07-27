<?php

namespace App\Services;

use Illuminate\Support\Collection;

class QuestionStatisticsCalculator
{
    /**
     * Sépare les réponses fausses des absences de réponse.
     * Le taux d'erreur porte uniquement sur les réponses données, tandis que
     * le taux de non-réponse porte sur toutes les soumissions du QCM.
     */
    public function calculate(iterable $questions, iterable $answers, int $submissionCount): Collection
    {
        $byQuestion = collect($answers)->groupBy(
            fn ($answer) => (int) data_get($answer, 'question_id')
        );

        return collect($questions)->map(function ($question) use ($byQuestion, $submissionCount) {
            $questionId = (int) data_get($question, 'id');
            $group = $byQuestion->get($questionId, collect());
            $answeredGroup = $group->filter(fn ($answer) => $this->isAnswered($answer));

            $answered = $answeredGroup->count();
            $wrong = $answeredGroup->filter(fn ($answer) => !(bool) data_get($answer, 'is_correct'))->count();
            $correct = $answered - $wrong;
            $unanswered = max(0, $submissionCount - $answered);

            return [
                'id' => $questionId,
                'body' => (string) data_get($question, 'body'),
                'total' => $submissionCount,
                'answered' => $answered,
                'unanswered' => $unanswered,
                'wrong' => $wrong,
                'correct' => $correct,
                'wrong_rate' => $answered > 0 ? (int) round($wrong / $answered * 100) : 0,
                'correct_rate' => $answered > 0 ? (int) round($correct / $answered * 100) : 0,
                'unanswered_rate' => $submissionCount > 0 ? (int) round($unanswered / $submissionCount * 100) : 0,
            ];
        })->sort(function (array $left, array $right) {
            return [$right['wrong_rate'], $right['wrong'], $right['unanswered_rate']]
                <=> [$left['wrong_rate'], $left['wrong'], $left['unanswered_rate']];
        })->values();
    }

    private function isAnswered(mixed $answer): bool
    {
        $selectedIds = data_get($answer, 'selected_choice_ids');

        if (is_string($selectedIds)) {
            $decoded = json_decode($selectedIds, true);
            $selectedIds = is_array($decoded) ? $decoded : null;
        }

        if (is_array($selectedIds)) {
            return count($selectedIds) > 0;
        }

        return data_get($answer, 'choice_id') !== null;
    }
}
