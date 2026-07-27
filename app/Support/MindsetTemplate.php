<?php

namespace App\Support;

final class MindsetTemplate
{
    public static function template(): array
    {
        return config('mindset');
    }

    public static function questions(): array
    {
        $questions = [];

        foreach (self::template()['pillars'] as $pillar) {
            foreach ($pillar['questions'] as $question) {
                $questions[$question['key']] = [
                    ...$question,
                    'pillar' => $pillar['key'],
                    'pillar_label' => $pillar['label'],
                ];
            }
        }

        return $questions;
    }

    public static function pillarLabels(): array
    {
        $labels = [];

        foreach (self::template()['pillars'] as $pillar) {
            $labels[$pillar['key']] = $pillar['label'];
        }

        return $labels;
    }

    public static function interpretationFor(int $score): array
    {
        foreach (self::template()['interpretations'] as $interpretation) {
            if ($score >= $interpretation['min'] && $score <= $interpretation['max']) {
                return $interpretation;
            }
        }

        return self::template()['interpretations'][0];
    }
}
