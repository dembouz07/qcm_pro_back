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
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
                    'created_at' => $quiz->created_at,
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

    /**
     * Liste les notes (soumissions) de l'élève connecté, tous QCM confondus.
     */
    public function results(Request $request)
    {
        $user = $request->user();

        $submissions = Submission::query()
            ->with('quiz.schoolClass')
            ->where('user_id', $user->id)
            ->latest('submitted_at')
            ->get()
            ->map(fn (Submission $s) => [
                'id' => $s->id,
                'quiz_title' => $s->quiz?->title,
                'quiz_type' => $s->quiz?->type,
                'school_class' => $s->quiz?->schoolClass?->name,
                'academic_year' => $s->quiz?->schoolClass?->academic_year,
                'score' => $s->score,
                'total_points' => $s->total_points,
                'percentage' => $s->percentage,
                'note_sur_20' => $s->note_sur_20,
                'stade_atteint' => $s->stade_atteint,
                'submitted_at' => $s->submitted_at,
                'quiz_id' => $s->quiz_id,
                'show_corrections' => (bool) ($s->quiz?->show_corrections),
            ]);

        return response()->json(['data' => $submissions]);
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
            'questions' => $quiz->questions->shuffle()->map(fn ($question) => [
                'id' => $question->id,
                'body' => $question->body,
                'points' => $question->points,
                'multiple' => $question->choices->where('is_correct', true)->count() > 1,
                'choices' => $question->choices->shuffle()->map(fn ($choice) => [
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
            'answers.*.choice_ids' => ['nullable', 'array'],
            'answers.*.choice_ids.*' => ['integer'],
        ]);

        $quiz->load('questions.choices');
        $questions = $quiz->questions->keyBy('id');
        $submittedAnswers = collect($data['answers'] ?? [])
            ->filter(fn ($answer) => isset($answer['question_id']))
            ->keyBy('question_id');

        // Récupère l'ensemble des choix sélectionnés pour une réponse (multi ou simple).
        $selectedFor = function ($answer) {
            if (!$answer) {
                return [];
            }
            if (!empty($answer['choice_ids']) && is_array($answer['choice_ids'])) {
                return array_values(array_unique(array_map('intval', $answer['choice_ids'])));
            }
            if (!empty($answer['choice_id'])) {
                return [(int) $answer['choice_id']];
            }
            return [];
        };

        if (!$isAutoSubmit) {
            $answeredCount = $submittedAnswers->filter(fn ($a) => count($selectedFor($a)) > 0)->count();
            if ($answeredCount !== $questions->count()) {
                throw ValidationException::withMessages([
                    'answers' => 'Vous devez répondre à toutes les questions.',
                ]);
            }
        }

        $totalPoints = (float) $quiz->questions->sum('points');
        $score = 0.0;

        $submission = DB::transaction(function () use ($request, $quiz, $questions, $submittedAnswers, $selectedFor, $totalPoints, &$score) {
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
                $validIds = $question->choices->pluck('id')->map(fn ($i) => (int) $i);

                // Choix sélectionnés qui appartiennent réellement à la question
                $selected = collect($selectedFor($answer))
                    ->filter(fn ($id) => $validIds->contains($id))
                    ->unique()->sort()->values();

                $correctIds = $question->choices->where('is_correct', true)
                    ->pluck('id')->map(fn ($i) => (int) $i)->sort()->values();

                // Correct uniquement si l'ensemble sélectionné == ensemble des bonnes réponses
                $isCorrect = $selected->isNotEmpty() && $selected->all() === $correctIds->all();
                $pointsAwarded = $isCorrect ? (float) $question->points : 0.0;
                $score += $pointsAwarded;

                $submission->answers()->create([
                    'question_id' => $question->id,
                    'choice_id' => $selected->count() === 1 ? $selected->first() : null,
                    'selected_choice_ids' => $selected->all(),
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
            'show_corrections' => (bool) $quiz->show_corrections,
            'correction' => $quiz->show_corrections ? $this->buildCorrection($quiz, $submission) : null,
        ], 201);
    }

    /**
     * Correction du QCM pour l'élève (si le formateur l'a activée et que l'élève a soumis).
     */
    public function correction(Request $request, Quiz $quiz)
    {
        $this->ensureStudentCanAccessQuiz($request, $quiz);

        $submission = Submission::where('user_id', $request->user()->id)
            ->where('quiz_id', $quiz->id)
            ->latest('submitted_at')
            ->first();

        if (!$submission) {
            return response()->json(['message' => "Vous n'avez pas encore passé ce QCM."], 404);
        }

        if (!$quiz->show_corrections) {
            return response()->json(['message' => "La correction n'est pas disponible pour ce QCM."], 403);
        }

        return response()->json($this->buildCorrection($quiz, $submission));
    }

    /**
     * Construit le détail de correction : chaque question avec le bon choix,
     * le choix de l'élève, et l'explication du formateur.
     */
    private function buildCorrection(Quiz $quiz, Submission $submission): array
    {
        $quiz->load('questions.choices');
        $answers = $submission->answers()->get()->keyBy('question_id');

        return [
            'quiz_title' => $quiz->title,
            'score' => $submission->score,
            'total_points' => $submission->total_points,
            'note_sur_20' => $submission->note_sur_20,
            'percentage' => $submission->percentage,
            'questions' => $quiz->questions->map(function ($question) use ($answers) {
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
