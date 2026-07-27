<?php

namespace App\Services;

class ProgressiveStageResultCalculator
{
    public function calculate(
        iterable $stageNumbers,
        array $stageScores,
        int $threshold,
        bool $requireStagePass,
    ): int {
        $scores = collect($stageScores)
            ->mapWithKeys(fn ($score, $stage) => [(int) $stage => (int) $score])
            ->all();

        $stages = collect($stageNumbers)
            ->map(fn ($stage) => (int) $stage)
            ->filter(fn ($stage) => $stage > 0)
            ->unique()
            ->sort()
            ->values();

        $reachedStage = 1;

        foreach ($stages as $stage) {
            if (!array_key_exists($stage, $scores)) {
                break;
            }

            $reachedStage = max($reachedStage, $stage);

            if (!$requireStagePass) {
                continue;
            }

            if ($scores[$stage] >= $threshold) {
                break;
            }
        }

        return $reachedStage;
    }
}
