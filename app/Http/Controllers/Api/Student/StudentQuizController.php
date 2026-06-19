<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Choice;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentQuizController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->load('schoolClass');

        if (!$user->school_class_id) {
            return response()->json([
                'message' => 'Aucune classe associée à ce compte élève.',
                'data' => [],
            ]);
        }

        $submissions = Submission::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('quiz_id');

        $quizzes = Quiz::query()
            ->with('schoolClass')
            ->withCount('questions')
            ->where('school_class_id', $user->school_class_id)
            ->where('is_published', true)
            ->orderBy('starts_at')
            ->get()
            ->map(function (Quiz $quiz) use ($submissions) {
                $submission = $submissions->get($quiz->id);

                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'school_class' => $quiz->schoolClass,
                    'starts_at' => $quiz->starts_at,
                    'ends_at' => $quiz->ends_at,
                    'questions_count' => $quiz->questions_count,
                    'status' => $this->statusFor($quiz, $submission),
                    'submission' => $submission,
                ];
            })
            ->values();

        return response()->json([
            'student_class' => $user->schoolClass,
            'data' => $quizzes,
        ]);
    }

    public function show(Request $request, Quiz $quiz)
    {
        $this->ensureStudentCanAccessQuiz($request, $quiz);

        if ($quiz->isLocked()) {
            return response()->json([
                'message' => "Ce QCM n'est pas encore ouvert.",
                'starts_at' => $quiz->starts_at,
            ], 423);
        }

        if ($quiz->isClosed()) {
            return response()->json([
                'message' => 'Ce QCM est fermé.',
            ], 403);
        }

        if ($this->alreadySubmitted($request, $quiz)) {
            return response()->json([
                'message' => 'Vous avez déjà envoyé ce QCM.',
            ], 409);
        }

        $quiz->load('questions.choices');

        return response()->json([
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'starts_at' => $quiz->starts_at,
            'ends_at' => $quiz->ends_at,
            'questions' => $quiz->questions->map(fn ($question) => [
                'id' => $question->id,
                'body' => $question->body,
                'points' => $question->points,
                'choices' => $question->choices->map(fn ($choice) => [
                    'id' => $choice->id,
                    'body' => $choice->body,
                ])->values(),
            ])->values(),
        ]);
    }

    public function submit(Request $request, Quiz $quiz)
    {
        $this->ensureStudentCanAccessQuiz($request, $quiz);

        if ($quiz->isLocked()) {
            return response()->json([
                'message' => "Ce QCM n'est pas encore ouvert.",
                'starts_at' => $quiz->starts_at,
            ], 423);
        }

        $isAutoSubmit = $request->boolean('auto_submit', false);
        $gracePeriodSeconds = $isAutoSubmit ? 60 : 0;

        // La soumission automatique a une petite marge pour éviter qu'un décalage réseau bloque l'élève.
        if ($quiz->isClosed($gracePeriodSeconds)) {
            return response()->json([
                'message' => 'Ce QCM est fermé.',
            ], 403);
        }

        if ($this->alreadySubmitted($request, $quiz)) {
            return response()->json([
                'message' => 'Vous avez déjà envoyé ce QCM.',
            ], 409);
        }

        $data = $request->validate([
            'auto_submit' => ['sometimes', 'boolean'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.choice_id' => ['nullable', 'integer'],
        ]);

        $quiz->load('questions.choices');
        $questions = $quiz->questions->keyBy('id');
        $submittedAnswers = collect($data['answers'] ?? [])
            ->filter(fn ($answer) => isset($answer['question_id']))
            ->keyBy('question_id');

        if (!$isAutoSubmit && $submittedAnswers->count() !== $questions->count()) {
            throw ValidationException::withMessages([
                'answers' => 'Vous devez répondre à toutes les questions.',
            ]);
        }

        $totalPoints = (float) $quiz->questions->sum('points');
        $score = 0.0;

        $submission = DB::transaction(function () use ($request, $quiz, $questions, $submittedAnswers, $totalPoints, &$score) {
            $submission = Submission::create([
                'user_id' => $request->user()->id,
                'quiz_id' => $quiz->id,
                'score' => 0,
                'total_points' => $totalPoints,
                'percentage' => 0,
                'note_sur_20' => 0,
                'submitted_at' => now(),
            ]);

            foreach ($questions as $question) {
                $answer = $submittedAnswers->get($question->id);
                $choice = null;

                if ($answer && !empty($answer['choice_id'])) {
                    $choice = Choice::where('id', $answer['choice_id'])
                        ->where('question_id', $question->id)
                        ->first();

                    if (!$choice) {
                        throw ValidationException::withMessages([
                            'answers' => 'Un choix envoyé ne correspond pas à sa question.',
                        ]);
                    }
                }

                $isCorrect = $choice ? (bool) $choice->is_correct : false;
                $pointsAwarded = $isCorrect ? (float) $question->points : 0.0;
                $score += $pointsAwarded;

                $submission->answers()->create([
                    'question_id' => $question->id,
                    'choice_id' => $choice?->id,
                    'is_correct' => $isCorrect,
                    'points_awarded' => $pointsAwarded,
                ]);
            }

            $percentage = $totalPoints > 0 ? round(($score / $totalPoints) * 100, 2) : 0;
            $noteSur20 = $totalPoints > 0 ? round(($score / $totalPoints) * 20, 2) : 0;

            $submission->update([
                'score' => $score,
                'percentage' => $percentage,
                'note_sur_20' => $noteSur20,
            ]);

            return $submission->fresh()->load('answers.question', 'answers.choice');
        });

        return response()->json([
            'message' => $isAutoSubmit
                ? 'Temps terminé : réponses envoyées automatiquement.'
                : 'Réponses envoyées avec succès.',
            'submission' => $submission,
        ], 201);
    }

    private function ensureStudentCanAccessQuiz(Request $request, Quiz $quiz): void
    {
        if (!$quiz->is_published || (int) $quiz->school_class_id !== (int) $request->user()->school_class_id) {
            abort(response()->json([
                'message' => "Ce QCM n'est pas disponible pour votre classe.",
            ], 403));
        }
    }

    private function alreadySubmitted(Request $request, Quiz $quiz): bool
    {
        return Submission::where('user_id', $request->user()->id)
            ->where('quiz_id', $quiz->id)
            ->exists();
    }

    private function statusFor(Quiz $quiz, ?Submission $submission): string
    {
        if ($submission) {
            return 'completed';
        }

        if ($quiz->isLocked()) {
            return 'locked';
        }

        if ($quiz->isClosed()) {
            return 'closed';
        }

        return 'open';
    }
}
