<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Choice;
use App\Models\ProductEvent;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Services\ProgressiveStageResultCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicQuizController extends Controller
{
    /**
     * Afficher les infos du quiz via son token (sans les questions si pas encore ouvert).
     */
    public function show(string $token)
    {
        $quiz = Quiz::where('access_token', $token)
            ->withCount('questions')
            ->first();

        if (!$quiz) {
            return response()->json(['message' => 'QCM introuvable.'], 404);
        }

        $data = [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'type' => $quiz->type,
            'stage_threshold' => $quiz->stage_threshold,
            'require_stage_pass' => $quiz->require_stage_pass,
            'starts_at' => $quiz->isProgressive() ? null : $quiz->starts_at,
            'ends_at' => $quiz->isProgressive() ? null : $quiz->ends_at,
            'questions_count' => $quiz->questions_count,
            'is_locked' => $quiz->isLocked(),
            'is_closed' => $quiz->isClosed(),
            'is_open' => $quiz->isOpen(),
        ];

        return response()->json($data);
    }

    /**
     * Commencer le quiz : vérifier que c'est ouvert et retourner les questions.
     */
    public function start(Request $request, string $token)
    {
        $quiz = Quiz::where('access_token', $token)->first();

        if (!$quiz) {
            return response()->json(['message' => 'QCM introuvable.'], 404);
        }

        if ($quiz->isLocked()) {
            return response()->json([
                'message' => "Ce QCM n'est pas encore ouvert.",
                'starts_at' => $quiz->starts_at,
            ], 423);
        }

        if ($quiz->isClosed()) {
            return response()->json(['message' => 'Ce QCM est fermé.'], 403);
        }

        $data = $request->validate([
            'attempt_id' => ['nullable', 'uuid'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'referentiel' => [$quiz->isProgressive() ? 'nullable' : 'required', 'string', 'max:200'],
        ]);

        [$attemptId, $resultAccessToken] = $this->recordAttemptStart($quiz, $data['attempt_id'] ?? null);

        $quiz->load('questions.choices');

        if ($quiz->isProgressive()) {
            return response()->json([
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'type' => 'progressive',
                'stage_threshold' => $quiz->stage_threshold,
                'require_stage_pass' => $quiz->require_stage_pass,
                'starts_at' => null,
                'ends_at' => null,
                'attempt_id' => $attemptId,
                'result_access_token' => $resultAccessToken,
                'stages' => $this->buildStages($quiz),
            ]);
        }

        return response()->json([
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'type' => 'standard',
            'starts_at' => $quiz->starts_at,
            'ends_at' => $quiz->ends_at,
            'attempt_id' => $attemptId,
            'result_access_token' => $resultAccessToken,
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

    /**
     * Regroupe les questions d'un QCM progressif par stade.
     */
    private function buildStages(Quiz $quiz): array
    {
        return $quiz->questions
            ->groupBy('stage')
            ->sortKeys()
            ->map(fn ($questions, $stage) => [
                'stage' => (int) $stage,
                'name' => $questions->first()?->stage_name ?: "Stade {$stage}",
                'questions' => $questions->map(fn ($question) => [
                    'id' => $question->id,
                    'body' => $question->body,
                    'choices' => $question->choices->map(fn ($choice) => [
                        'id' => $choice->id,
                        'body' => $choice->body,
                        'is_oui' => (bool) $choice->is_correct,
                    ])->values(),
                ])->values(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Retourne un résultat public avec son code secret temporaire.
     */
    public function myResults(Request $request)
    {
        $data = $request->validate([
            'access_token' => ['required', 'alpha_num', 'size:64'],
        ]);

        $attempt = QuizAttempt::query()
            ->where('result_access_token_hash', hash('sha256', $data['access_token']))
            ->where('result_access_expires_at', '>=', now())
            ->whereNotNull('submission_id')
            ->with('submission.quiz')
            ->first();

        if (!$attempt?->submission) {
            return response()->json(['message' => 'Résultat introuvable ou code d’accès invalide.'], 404);
        }

        $submissions = collect([$attempt->submission])->map(fn ($s) => [
            'id' => $s->id,
            'quiz_title' => $s->quiz?->title,
            'quiz_type' => $s->quiz?->type,
            'note_sur_20' => $s->note_sur_20,
            'score' => $s->score,
            'total_points' => $s->total_points,
            'percentage' => $s->percentage,
            'stade_atteint' => $s->stade_atteint,
            'referentiel' => $s->participant_referentiel,
            'submitted_at' => $s->submitted_at,
        ]);

        return response()->json(['data' => $submissions]);
    }

    /**
     * Soumettre les réponses en mode public (sans auth).
     */
    public function submit(Request $request, string $token, ProgressiveStageResultCalculator $stageCalculator)
    {
        $quiz = Quiz::where('access_token', $token)->first();

        if (!$quiz) {
            return response()->json(['message' => 'QCM introuvable.'], 404);
        }

        $isAutoSubmit = $request->boolean('auto_submit', false);

        $data = $request->validate([
            'attempt_id' => ['required', 'uuid'],
            'result_access_token' => ['required', 'alpha_num', 'size:64'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'referentiel' => [$quiz->isProgressive() ? 'nullable' : 'required', 'string', 'max:200'],
            'auto_submit' => ['sometimes', 'boolean'],
            'answers' => ['nullable', 'array', 'max:250'],
            'answers.*' => ['array:question_id,choice_id'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.choice_id' => ['nullable', 'integer'],
        ]);

        $attempt = $this->ensureAttemptMatchesQuiz(
            $quiz,
            $data['attempt_id'],
            $data['result_access_token'],
        );

        if ($attempt->submitted_at) {
            return $this->submittedAttemptResponse($quiz, $attempt);
        }

        if ($quiz->isLocked()) {
            return response()->json([
                'message' => "Ce QCM n'est pas encore ouvert.",
                'starts_at' => $quiz->starts_at,
            ], 423);
        }

        $gracePeriodSeconds = $isAutoSubmit ? 60 : 0;

        if ($quiz->isClosed($gracePeriodSeconds)) {
            return response()->json(['message' => 'Ce QCM est fermé.'], 403);
        }

        $quiz->load('questions.choices');

        if ($quiz->isProgressive()) {
            return $this->submitProgressive($quiz, $data, $stageCalculator);
        }

        $questions = $quiz->questions->keyBy('id');
        $rawAnswers = collect($data['answers'] ?? [])
            ->filter(fn ($answer) => isset($answer['question_id']))
            ->values();
        $submittedAnswers = $rawAnswers->keyBy('question_id');
        $questionIds = $questions->keys()->map(fn ($id) => (int) $id);
        $submittedQuestionIds = $submittedAnswers->keys()->map(fn ($id) => (int) $id);

        if ($rawAnswers->count() !== $submittedAnswers->count()
            || $submittedQuestionIds->diff($questionIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Les réponses contiennent une question inconnue ou dupliquée.',
            ]);
        }

        $isComplete = $submittedAnswers->count() === $questions->count()
            && $questionIds->diff($submittedQuestionIds)->isEmpty()
            && $submittedAnswers->every(fn ($answer) => !empty($answer['choice_id']));

        if (!$isAutoSubmit && !$isComplete) {
            throw ValidationException::withMessages([
                'answers' => 'Vous devez répondre à toutes les questions.',
            ]);
        }

        $totalPoints = (float) $quiz->questions->sum('points');
        $score = 0.0;

        $submission = DB::transaction(function () use ($quiz, $data, $questions, $submittedAnswers, $totalPoints, $isAutoSubmit, $isComplete, &$score) {
            $this->lockAttemptForSubmission($quiz, $data['attempt_id']);

            $submission = Submission::create([
                'user_id' => null,
                'quiz_id' => $quiz->id,
                'participant_nom' => $data['nom'],
                'participant_prenom' => $data['prenom'],
                'participant_referentiel' => $data['referentiel'] ?? null,
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

            $this->markAttemptSubmitted(
                $quiz,
                $data['attempt_id'],
                $isAutoSubmit ? 'automatic' : 'manual',
                $submission,
                $isComplete,
                $isComplete ? null : 'automatic_incomplete',
            );

            return $submission->fresh()->load('answers.question', 'answers.choice');
        });

        return response()->json([
            'message' => $isAutoSubmit
                ? 'Temps terminé : réponses envoyées automatiquement.'
                : 'Réponses envoyées avec succès.',
            'submission' => $submission,
        ], 201);
    }

    /**
     * Soumission d'un diagnostic progressif : calcule le score par stade
     * et le stade atteint (le seuil de « Oui » bloque le passage au stade suivant).
     */
    private function submitProgressive(
        Quiz $quiz,
        array $data,
        ProgressiveStageResultCalculator $stageCalculator,
    )
    {
        $rawAnswers = collect($data['answers'] ?? [])
            ->filter(fn ($answer) => isset($answer['question_id']))
            ->values();
        $submittedAnswers = $rawAnswers->keyBy('question_id');

        $this->validateProgressiveAnswerPath(
            $quiz,
            $quiz->questions->keyBy('id'),
            $rawAnswers,
            $submittedAnswers,
        );

        return DB::transaction(function () use ($quiz, $data, $stageCalculator) {
            $this->lockAttemptForSubmission($quiz, $data['attempt_id']);

            return $this->persistProgressive($quiz, $data, $stageCalculator);
        });
    }

    private function persistProgressive(
        Quiz $quiz,
        array $data,
        ProgressiveStageResultCalculator $stageCalculator,
    )
    {
        $questions = $quiz->questions->keyBy('id');
        $submittedAnswers = collect($data['answers'] ?? [])
            ->filter(fn ($answer) => isset($answer['question_id']))
            ->keyBy('question_id');

        // Score par stade = nombre de "Oui"
        $stageScores = [];
        $globalScore = 0.0;

        $submission = DB::transaction(function () use ($quiz, $data, $questions, $submittedAnswers, &$stageScores, &$globalScore) {
            $submission = Submission::create([
                'user_id' => null,
                'quiz_id' => $quiz->id,
                'participant_nom' => $data['nom'],
                'participant_prenom' => $data['prenom'],
                'participant_referentiel' => $data['referentiel'] ?? null,
                'score' => 0,
                'total_points' => (float) $quiz->questions->sum('points'),
                'percentage' => 0,
                'note_sur_20' => 0,
                'submitted_at' => now(),
            ]);

            foreach ($submittedAnswers as $answer) {
                $question = $questions->get($answer['question_id']);
                if (!$question) {
                    continue;
                }

                $choice = null;
                if (!empty($answer['choice_id'])) {
                    $choice = Choice::where('id', $answer['choice_id'])
                        ->where('question_id', $question->id)
                        ->first();

                    if (!$choice) {
                        throw ValidationException::withMessages([
                            'answers' => 'Un choix envoyé ne correspond pas à sa question.',
                        ]);
                    }
                }

                $isOui = $choice ? (bool) $choice->is_correct : false;
                $points = $isOui ? 1.0 : 0.0;
                $globalScore += $points;

                $stage = (int) $question->stage;
                $stageScores[$stage] = ($stageScores[$stage] ?? 0) + ($isOui ? 1 : 0);

                $submission->answers()->create([
                    'question_id' => $question->id,
                    'choice_id' => $choice?->id,
                    'is_correct' => $isOui,
                    'points_awarded' => $points,
                ]);
            }

            return $submission;
        });

        // Avec le blocage actif, atteindre le seuil arrête la progression au stade courant.
        // Sinon, le stade atteint est le dernier stade effectivement parcouru.
        $threshold = (int) $quiz->stage_threshold;
        ksort($stageScores);
        $stageNumbers = $quiz->questions
            ->pluck('stage')
            ->filter()
            ->map(fn ($stage) => (int) $stage)
            ->unique()
            ->sort()
            ->values();

        $stadeAtteint = $stageCalculator->calculate(
            $stageNumbers,
            $stageScores,
            $threshold,
            $quiz->require_stage_pass,
        );

        $submission->update([
            'score' => $globalScore,
            'stade_atteint' => $stadeAtteint,
            'stage_scores' => $stageScores,
        ]);

        $this->markAttemptSubmitted($quiz, $data['attempt_id'], 'manual', $submission, true);

        return response()->json([
            'message' => 'Diagnostic terminé.',
            'submission' => $submission->fresh(),
            'stade_atteint' => $stadeAtteint,
            'stage_scores' => $stageScores,
        ], 201);
    }

    private function validateProgressiveAnswerPath(
        Quiz $quiz,
        Collection $questions,
        Collection $rawAnswers,
        Collection $submittedAnswers,
    ): void {
        $questionIds = $questions->keys()->map(fn ($id) => (int) $id);
        $submittedQuestionIds = $submittedAnswers->keys()->map(fn ($id) => (int) $id);

        if ($rawAnswers->isEmpty()
            || $rawAnswers->count() !== $submittedAnswers->count()
            || $submittedQuestionIds->diff($questionIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Le parcours contient une question inconnue, dupliquée ou aucune réponse.',
            ]);
        }

        foreach ($submittedAnswers as $questionId => $answer) {
            $question = $questions->get((int) $questionId);
            $choiceId = $answer['choice_id'] ?? null;

            if (!$choiceId || !$question->choices->contains(
                fn ($choice) => (int) $choice->id === (int) $choiceId,
            )) {
                throw ValidationException::withMessages([
                    'answers' => 'Chaque réponse doit correspondre à un choix de sa question.',
                ]);
            }
        }

        $stages = $questions
            ->groupBy(fn ($question) => (int) $question->stage)
            ->sortKeys();
        $lastStage = (int) $stages->keys()->last();
        $consumedQuestionIds = collect();

        foreach ($stages as $stageNumber => $stageQuestions) {
            $stageQuestionIds = $stageQuestions->pluck('id')->map(fn ($id) => (int) $id);
            $answeredStageIds = $stageQuestionIds->filter(
                fn ($questionId) => $submittedAnswers->has($questionId),
            );

            if ($answeredStageIds->count() !== $stageQuestionIds->count()) {
                throw ValidationException::withMessages([
                    'answers' => 'Chaque stade atteint doit être entièrement renseigné.',
                ]);
            }

            $consumedQuestionIds = $consumedQuestionIds->merge($stageQuestionIds);
            $yesCount = $stageQuestions->filter(function ($question) use ($submittedAnswers) {
                $choiceId = $submittedAnswers->get($question->id)['choice_id'];

                return $question->choices->contains(
                    fn ($choice) => (int) $choice->id === (int) $choiceId && (bool) $choice->is_correct,
                );
            })->count();

            $mustStop = $quiz->require_stage_pass
                && $yesCount >= (int) $quiz->stage_threshold;
            $isLastStage = (int) $stageNumber === $lastStage;

            if ($mustStop || $isLastStage) {
                if ($submittedQuestionIds->diff($consumedQuestionIds)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'answers' => 'Des réponses ont été envoyées après le stade de fin du parcours.',
                    ]);
                }

                return;
            }
        }

        throw ValidationException::withMessages([
            'answers' => 'Le parcours progressif n’est pas terminé.',
        ]);
    }

    private function recordAttemptStart(Quiz $quiz, ?string $attemptId): array
    {
        $clientAttemptId = $attemptId;
        $attemptId ??= (string) Str::uuid();
        $existing = QuizAttempt::find($attemptId);
        $resultAccessToken = $existing ? null : Str::random(64);

        if ($clientAttemptId && !$existing) {
            throw ValidationException::withMessages([
                'attempt_id' => 'Cette tentative est inconnue.',
            ]);
        }
        if ($existing && (int) $existing->quiz_id !== (int) $quiz->id) {
            throw ValidationException::withMessages([
                'attempt_id' => 'Cette tentative ne correspond pas à ce QCM.',
            ]);
        }

        if ($existing?->submitted_at) {
            abort(response()->json([
                'message' => 'Cette tentative a déjà été envoyée.',
            ], 409));
        }

        $startedAt = now();
        $maturesAt = $quiz->ends_at
            ? $quiz->ends_at->copy()->addSeconds(60)
            : $startedAt->copy()->addHours(24);

        QuizAttempt::firstOrCreate(
            ['id' => $attemptId],
            [
                'quiz_id' => $quiz->id,
                'result_access_token_hash' => $resultAccessToken ? hash('sha256', $resultAccessToken) : null,
                'result_access_expires_at' => now()->addDays(30),
                'channel' => 'public_link',
                'environment' => (string) config('analytics.metric_environment', app()->environment()),
                'is_internal' => $quiz->creator
                    ? ProductEvent::isInternalUser($quiz->creator)
                    : false,
                'started_at' => $startedAt,
                'matures_at' => $maturesAt,
            ],
        );

        return [$attemptId, $resultAccessToken];
    }

    private function lockAttemptForSubmission(Quiz $quiz, string $attemptId): QuizAttempt
    {
        $attempt = QuizAttempt::query()
            ->whereKey($attemptId)
            ->where('quiz_id', $quiz->id)
            ->lockForUpdate()
            ->first();

        if (!$attempt) {
            throw ValidationException::withMessages([
                'attempt_id' => 'Cette tentative est inconnue.',
            ]);
        }

        if ($attempt->submitted_at) {
            abort(response()->json([
                'message' => 'Cette tentative a déjà été envoyée.',
            ], 409));
        }

        return $attempt;
    }

    private function markAttemptSubmitted(
        Quiz $quiz,
        string $attemptId,
        string $mode,
        Submission $submission,
        bool $isValidCompletion,
        ?string $invalidReason = null,
    ): void
    {
        $updated = QuizAttempt::query()
            ->whereKey($attemptId)
            ->where('quiz_id', $quiz->id)
            ->whereNull('submitted_at')
            ->update([
                'submission_id' => $submission->id,
                'submitted_at' => now(),
                'submission_mode' => $mode,
                'is_valid_completion' => $isValidCompletion,
                'invalid_reason' => $invalidReason,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            abort(response()->json([
                'message' => 'Cette tentative a déjà été envoyée.',
            ], 409));
        }
    }

    private function ensureAttemptMatchesQuiz(
        Quiz $quiz,
        string $attemptId,
        string $resultAccessToken,
    ): QuizAttempt
    {
        $attempt = QuizAttempt::find($attemptId);

        if (!$attempt
            || (int) $attempt->quiz_id !== (int) $quiz->id
            || !$attempt->result_access_token_hash
            || !$attempt->result_access_expires_at
            || $attempt->result_access_expires_at->isPast()
            || !hash_equals(
                $attempt->result_access_token_hash,
                hash('sha256', $resultAccessToken),
            )) {
            throw ValidationException::withMessages([
                'attempt_id' => 'Cette tentative ou son code secret est invalide.',
            ]);
        }

        return $attempt;
    }

    private function submittedAttemptResponse(Quiz $quiz, QuizAttempt $attempt)
    {
        $submission = $attempt->submission()
            ->with('answers.question', 'answers.choice')
            ->first();

        if (!$submission) {
            abort(response()->json([
                'message' => 'Cette tentative est marquée comme envoyée, mais son résultat est indisponible.',
            ], 409));
        }

        if ($quiz->isProgressive()) {
            return response()->json([
                'message' => 'Diagnostic déjà enregistré.',
                'already_submitted' => true,
                'submission' => $submission,
                'stade_atteint' => $submission->stade_atteint,
                'stage_scores' => $submission->stage_scores,
            ]);
        }

        return response()->json([
            'message' => 'Réponses déjà enregistrées.',
            'already_submitted' => true,
            'submission' => $submission,
        ]);
    }
}
