<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProgressiveQuizCreator
{
    /**
     * Crée un QCM progressif (diagnostic par stades).
     * Chaque question est de type Oui/Non : "Oui" rapporte 1 point.
     *
     * $data['stages'] = [
     *   ['name' => 'Stade 1', 'questions' => ['texte q1', 'texte q2', ...]],
     *   ...
     * ]
     */
    public function create(array $data, ?User $admin = null): Quiz
    {
        $this->assertValidStages($data['stages'] ?? [], (int) ($data['stage_threshold'] ?? 0));

        return DB::transaction(function () use ($data, $admin) {
            $quiz = Quiz::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => 'progressive',
                'stage_threshold' => $data['stage_threshold'] ?? 5,
                'require_stage_pass' => $data['require_stage_pass'] ?? true,
                'school_class_id' => null,
                'created_by' => $admin?->id,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'is_published' => $data['is_published'] ?? true,
                'access_token' => Str::random(32),
            ]);

            $orderIndex = 1;

            foreach ($data['stages'] as $stageIndex => $stage) {
                $stageNumber = $stageIndex + 1;
                $stageName = trim((string) ($stage['name'] ?? '')) ?: "Stade {$stageNumber}";

                foreach ($stage['questions'] as $questionText) {
                    if (trim((string) $questionText) === '') {
                        continue;
                    }

                    $question = $quiz->questions()->create([
                        'body' => $questionText,
                        'points' => 1,
                        'order_index' => $orderIndex++,
                        'stage' => $stageNumber,
                        'stage_name' => $stageName,
                    ]);

                    // Oui = bonne réponse (1 point), Non = 0 point
                    $question->choices()->create([
                        'body' => 'Oui',
                        'is_correct' => true,
                        'order_index' => 1,
                    ]);
                    $question->choices()->create([
                        'body' => 'Non',
                        'is_correct' => false,
                        'order_index' => 2,
                    ]);
                }
            }

            return $quiz->load('schoolClass', 'questions.choices');
        });
    }

    public function update(Quiz $quiz, array $data): Quiz
    {
        $this->assertValidStages($data['stages'] ?? [], (int) ($data['stage_threshold'] ?? 0));

        return DB::transaction(function () use ($quiz, $data) {
            $quiz->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => 'progressive',
                'stage_threshold' => $data['stage_threshold'] ?? 5,
                'require_stage_pass' => $data['require_stage_pass'] ?? $quiz->require_stage_pass,
                'school_class_id' => null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'is_published' => $data['is_published'] ?? true,
            ]);

            $quiz->questions()->delete();

            $orderIndex = 1;

            foreach ($data['stages'] as $stageIndex => $stage) {
                $stageNumber = $stageIndex + 1;
                $stageName = trim((string) ($stage['name'] ?? '')) ?: "Stade {$stageNumber}";

                foreach ($stage['questions'] as $questionText) {
                    if (trim((string) $questionText) === '') {
                        continue;
                    }

                    $question = $quiz->questions()->create([
                        'body' => $questionText,
                        'points' => 1,
                        'order_index' => $orderIndex++,
                        'stage' => $stageNumber,
                        'stage_name' => $stageName,
                    ]);

                    $question->choices()->create([
                        'body' => 'Oui',
                        'is_correct' => true,
                        'order_index' => 1,
                    ]);
                    $question->choices()->create([
                        'body' => 'Non',
                        'is_correct' => false,
                        'order_index' => 2,
                    ]);
                }
            }

            return $quiz->fresh()->load('schoolClass', 'questions.choices');
        });
    }

    private function assertValidStages(array $stages, int $threshold): void
    {
        if (count($stages) < 1) {
            throw ValidationException::withMessages([
                'stages' => 'Le diagnostic doit contenir au moins un stade.',
            ]);
        }

        foreach ($stages as $index => $stage) {
            $questions = array_filter(
                $stage['questions'] ?? [],
                fn ($q) => trim((string) $q) !== ''
            );

            if (count($questions) < 1) {
                throw ValidationException::withMessages([
                    "stages.$index.questions" => 'Chaque stade doit contenir au moins une question.',
                ]);
            }

            if ($threshold > count($questions)) {
                throw ValidationException::withMessages([
                    "stages.$index.questions" => sprintf(
                        'Le seuil de %d « Oui » dépasse les %d question(s) de ce stade.',
                        $threshold,
                        count($questions)
                    ),
                ]);
            }

            $normalizedQuestions = collect($questions)
                ->map(fn ($question) => mb_strtolower(trim((string) $question), 'UTF-8'));
            if ($normalizedQuestions->unique()->count() !== $normalizedQuestions->count()) {
                throw ValidationException::withMessages([
                    "stages.$index.questions" => 'Les questions d’un même stade doivent être différentes.',
                ]);
            }
        }
    }
}
