<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Choice;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'starts_at' => $quiz->starts_at,
            'ends_at' => $quiz->ends_at,
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
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'referentiel' => ['required', 'string', 'max:200'],
        ]);

        // Vérifier si cette personne a déjà soumis
        $alreadySubmitted = Submission::where('quiz_id', $quiz->id)
            ->where('participant_nom', $data['nom'])
            ->where('participant_prenom', $data['prenom'])
            ->where('participant_referentiel', $data['referentiel'])
            ->exists();

        if ($alreadySubmitted) {
            return response()->json([
                'message' => 'Vous avez déjà passé ce QCM.',
            ], 409);
        }

        $quiz->load('questions.choices');

        if ($quiz->isProgressive()) {
            return response()->json([
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'type' => 'progressive',
                'stage_threshold' => $quiz->stage_threshold,
                'starts_at' => $quiz->starts_at,
                'ends_at' => $quiz->ends_at,
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
     * Soumettre les réponses en mode public (sans auth).
     */
    public function submit(Request $request, string $token)
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

        $isAutoSubmit = $request->boolean('auto_submit', false);
        $gracePeriodSeconds = $isAutoSubmit ? 60 : 0;

        if ($quiz->isClosed($gracePeriodSeconds)) {
            return response()->json(['message' => 'Ce QCM est fermé.'], 403);
        }

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'referentiel' => ['required', 'string', 'max:200'],
            'auto_submit' => ['sometimes', 'boolean'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.choice_id' => ['nullable', 'integer'],
        ]);

        // Vérifier doublon
        $alreadySubmitted = Submission::where('quiz_id', $quiz->id)
            ->where('participant_nom', $data['nom'])
            ->where('participant_prenom', $data['prenom'])
            ->where('participant_referentiel', $data['referentiel'])
            ->exists();

        if ($alreadySubmitted) {
            return response()->json([
                'message' => 'Vous avez déjà passé ce QCM.',
            ], 409);
        }

        $quiz->load('questions.choices');

        if ($quiz->isProgressive()) {
            return $this->submitProgressive($quiz, $data);
        }

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

        $submission = DB::transaction(function () use ($quiz, $data, $questions, $submittedAnswers, $totalPoints, &$score) {
            $submission = Submission::create([
                'user_id' => null,
                'quiz_id' => $quiz->id,
                'participant_nom' => $data['nom'],
                'participant_prenom' => $data['prenom'],
                'participant_referentiel' => $data['referentiel'],
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

    /**
     * Soumission d'un diagnostic progressif : calcule le score par stade
     * et le stade atteint (dernier stade validé : score >= seuil).
     */
    private function submitProgressive(Quiz $quiz, array $data)
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
                'participant_referentiel' => $data['referentiel'],
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

        // Déterminer le stade atteint : dernier stade dont le score >= seuil
        $threshold = (int) $quiz->stage_threshold;
        ksort($stageScores);
        $stadeAtteint = 1;
        foreach ($stageScores as $stage => $oui) {
            if ($oui >= $threshold) {
                $stadeAtteint = max($stadeAtteint, $stage);
            }
        }

        $submission->update([
            'score' => $globalScore,
            'stade_atteint' => $stadeAtteint,
            'stage_scores' => $stageScores,
        ]);

        return response()->json([
            'message' => 'Diagnostic terminé.',
            'submission' => $submission->fresh(),
            'stade_atteint' => $stadeAtteint,
            'stage_scores' => $stageScores,
        ], 201);
    }
}