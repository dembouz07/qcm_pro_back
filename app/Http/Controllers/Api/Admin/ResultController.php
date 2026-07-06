<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function index(Request $request)
    {
        $adminId = $request->user()->id;

        $query = Submission::query()
            ->whereHas('quiz', fn ($q) => $q->where('created_by', $adminId))
            ->with([
                'user.schoolClass',
                'quiz.schoolClass',
                'answers.question',
                'answers.choice',
            ])
            ->latest('submitted_at');

        if ($request->filled('quiz_id')) {
            $query->where('quiz_id', $request->integer('quiz_id'));
        }

        if ($request->filled('class_id')) {
            $query->whereHas('quiz', fn ($quizQuery) => $quizQuery->where('school_class_id', $request->integer('class_id')));
        }

        return $query->get();
    }

    public function show(Request $request, Submission $submission)
    {
        if ((int) $submission->quiz?->created_by !== (int) $request->user()->id) {
            abort(response()->json(['message' => 'Accès refusé.'], 403));
        }

        $submission->load([
            'user.schoolClass',
            'quiz.schoolClass',
            'quiz.questions.choices',
            'answers',
        ]);

        $quiz = $submission->quiz;
        $answers = $submission->answers->keyBy('question_id');

        $correction = [
            'quiz_title' => $quiz?->title,
            'score' => $submission->score,
            'total_points' => $submission->total_points,
            'note_sur_20' => $submission->note_sur_20,
            'percentage' => $submission->percentage,
            'questions' => ($quiz?->questions ?? collect())->map(function ($question) use ($answers) {
                $answer = $answers->get($question->id);
                $chosenId = $answer?->choice_id;

                return [
                    'id' => $question->id,
                    'body' => $question->body,
                    'explanation' => $question->explanation,
                    'points' => $question->points,
                    'is_correct' => $answer ? (bool) $answer->is_correct : false,
                    'chosen_choice_id' => $chosenId,
                    'choices' => $question->choices->map(fn ($choice) => [
                        'id' => $choice->id,
                        'body' => $choice->body,
                        'is_correct' => (bool) $choice->is_correct,
                        'chosen' => $chosenId === $choice->id,
                    ])->values(),
                ];
            })->values(),
        ];

        return response()->json([
            'submission' => $submission,
            'student' => [
                'name' => $submission->user?->name
                    ?? trim(($submission->participant_prenom ?? '') . ' ' . ($submission->participant_nom ?? ''))
                    ?: 'Anonyme',
                'email' => $submission->user?->email,
                'class' => $submission->user?->schoolClass?->name ?? $quiz?->schoolClass?->name,
            ],
            'correction' => $correction,
        ]);
    }
}
