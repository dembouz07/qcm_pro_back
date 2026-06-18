<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizCreator
{
    public function createWithQuestions(array $data, User $admin): Quiz
    {
        $this->assertValidQuestions($data['questions'] ?? []);

        return DB::transaction(function () use ($data, $admin) {
            $quiz = Quiz::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'school_class_id' => $data['school_class_id'],
                'created_by' => $admin->id,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'is_published' => $data['is_published'] ?? true,
            ]);

            $this->createQuestions($quiz, $data['questions']);

            return $quiz->load('schoolClass', 'questions.choices');
        });
    }

    public function updateWithQuestions(Quiz $quiz, array $data): Quiz
    {
        $this->assertValidQuestions($data['questions'] ?? []);

        return DB::transaction(function () use ($quiz, $data) {
            $quiz->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'school_class_id' => $data['school_class_id'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'is_published' => $data['is_published'] ?? true,
            ]);

            $quiz->questions()->delete();
            $this->createQuestions($quiz, $data['questions']);

            return $quiz->fresh()->load('schoolClass', 'questions.choices');
        });
    }

    public function assertValidQuestions(array $questions): void
    {
        if (count($questions) < 1) {
            throw ValidationException::withMessages([
                'questions' => 'Le QCM doit contenir au moins une question.',
            ]);
        }

        foreach ($questions as $questionIndex => $question) {
            $choices = $question['choices'] ?? [];

            if (count($choices) < 2) {
                throw ValidationException::withMessages([
                    "questions.$questionIndex.choices" => 'Chaque question doit contenir au moins deux choix.',
                ]);
            }

            $correctCount = collect($choices)->filter(fn ($choice) => (bool) Arr::get($choice, 'is_correct'))->count();

            if ($correctCount !== 1) {
                throw ValidationException::withMessages([
                    "questions.$questionIndex.choices" => 'Chaque question doit avoir exactement une bonne réponse.',
                ]);
            }
        }
    }

    private function createQuestions(Quiz $quiz, array $questions): void
    {
        foreach ($questions as $questionIndex => $questionData) {
            $question = $quiz->questions()->create([
                'body' => $questionData['body'],
                'points' => $questionData['points'] ?? 1,
                'order_index' => $questionIndex + 1,
            ]);

            foreach ($questionData['choices'] as $choiceIndex => $choiceData) {
                $question->choices()->create([
                    'body' => $choiceData['body'],
                    'is_correct' => (bool) $choiceData['is_correct'],
                    'order_index' => $choiceIndex + 1,
                ]);
            }
        }
    }
}
