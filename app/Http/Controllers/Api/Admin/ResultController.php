<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Models\User;
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
                $chosen = $answer?->selected_choice_ids ?? ($answer?->choice_id ? [$answer->choice_id] : []);
                $chosen = array_map('intval', $chosen);

                return [
                    'id' => $question->id,
                    'body' => $question->body,
                    'explanation' => $question->explanation,
                    'points' => $question->points,
                    'multiple' => $question->choices->where('is_correct', true)->count() > 1,
                    'is_correct' => $answer ? (bool) $answer->is_correct : false,
                    'chosen_choice_ids' => $chosen,
                    'choices' => $question->choices->map(fn ($choice) => [
                        'id' => $choice->id,
                        'body' => $choice->body,
                        'is_correct' => (bool) $choice->is_correct,
                        'chosen' => in_array((int) $choice->id, $chosen, true),
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

    public function studentResults(Request $request, User $student)
    {
        $student->load('schoolClass');

        if (
            !$student->isStudent()
            || $student->schoolClass === null
            || (int) $student->schoolClass->owner_id !== (int) $request->user()->id
        ) {
            abort(response()->json(['message' => 'Cet élève ne fait pas partie de vos classes.'], 403));
        }

        $submissions = Submission::query()
            ->where('user_id', $student->id)
            ->whereHas('quiz', fn ($query) => $query->where('created_by', $request->user()->id))
            ->with('quiz.schoolClass')
            ->latest('submitted_at')
            ->get();

        $gradedSubmissions = $submissions
            ->filter(fn (Submission $submission) => !$submission->quiz?->isProgressive());

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'class' => [
                    'id' => $student->schoolClass->id,
                    'name' => $student->schoolClass->name,
                ],
            ],
            'stats' => [
                'submissions_count' => $submissions->count(),
                'graded_count' => $gradedSubmissions->count(),
                'average_note' => $gradedSubmissions->isEmpty()
                    ? null
                    : round((float) $gradedSubmissions->avg('note_sur_20'), 2),
                'best_note' => $gradedSubmissions->isEmpty()
                    ? null
                    : round((float) $gradedSubmissions->max('note_sur_20'), 2),
                'last_submission_at' => $submissions->first()?->submitted_at,
            ],
            'results' => $submissions->map(fn (Submission $submission) => [
                'id' => $submission->id,
                'score' => $submission->score,
                'total_points' => $submission->total_points,
                'percentage' => $submission->percentage,
                'note_sur_20' => $submission->note_sur_20,
                'stade_atteint' => $submission->stade_atteint,
                'stage_scores' => $submission->stage_scores,
                'submitted_at' => $submission->submitted_at,
                'quiz' => [
                    'id' => $submission->quiz?->id,
                    'title' => $submission->quiz?->title,
                    'type' => $submission->quiz?->type,
                    'class' => $submission->quiz?->schoolClass?->name,
                ],
            ])->values(),
        ]);
    }
}
