<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicSurveyController extends Controller
{
    // Récupère un sondage public (sans authentification).
    public function show(string $token)
    {
        $survey = Survey::with('owner')->where('access_token', $token)->first();

        if (!$survey) {
            return response()->json(['message' => 'Sondage introuvable.'], 404);
        }
        if (!$survey->owner?->hasFeature('surveys')) {
            return response()->json(['message' => 'Ce sondage n’est pas disponible avec la formule actuelle du formateur.'], 403);
        }
        if (!$survey->is_open) {
            return response()->json(['message' => 'Ce sondage est fermé.'], 403);
        }

        return response()->json([
            'title' => $survey->title,
            'description' => $survey->description,
            'questions' => $survey->questions,
        ]);
    }

    // Enregistre une réponse anonyme.
    public function respond(Request $request, string $token)
    {
        $survey = Survey::with('owner')->where('access_token', $token)->first();

        if (!$survey) {
            return response()->json(['message' => 'Sondage introuvable.'], 404);
        }
        if (!$survey->owner?->hasFeature('surveys')) {
            return response()->json(['message' => 'Ce sondage n’est pas disponible avec la formule actuelle du formateur.'], 403);
        }
        if (!$survey->is_open) {
            return response()->json(['message' => 'Ce sondage est fermé.'], 403);
        }

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:100'],
        ]);

        // On ne conserve que les réponses correspondant à des questions existantes.
        $questions = collect($survey->questions)->keyBy(fn ($question) => (string) $question['id']);
        $submittedIds = collect(array_keys($data['answers']))->map(fn ($id) => (string) $id);

        if ($submittedIds->diff($questions->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Les réponses contiennent une question inconnue.',
            ]);
        }

        $answers = [];
        $errors = [];

        foreach ($questions as $questionId => $question) {
            if (!array_key_exists($questionId, $data['answers'])) {
                $errors["answers.{$questionId}"] = 'Cette question requiert une réponse.';
                continue;
            }

            $value = $data['answers'][$questionId];
            $type = $question['type'] ?? 'text';
            $options = array_values($question['options'] ?? []);

            if ($type === 'text') {
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) {
                    $errors["answers.{$questionId}"] = 'La réponse texte doit contenir entre 1 et 2 000 caractères.';
                    continue;
                }
                $answers[$questionId] = trim($value);
                continue;
            }

            if ($type === 'single') {
                if (!is_string($value) || !in_array($value, $options, true)) {
                    $errors["answers.{$questionId}"] = 'Choisissez une option proposée.';
                    continue;
                }
                $answers[$questionId] = $value;
                continue;
            }

            if (!is_array($value)
                || $value === []
                || count($value) > 20
                || count(array_unique($value, SORT_STRING)) !== count($value)
                || collect($value)->contains(fn ($item) => !is_string($item) || !in_array($item, $options, true))) {
                $errors["answers.{$questionId}"] = 'Choisissez une ou plusieurs options proposées, sans doublon.';
                continue;
            }

            $answers[$questionId] = array_values($value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $survey->responses()->create([
            'answers' => $answers,
            'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Merci, votre réponse a été enregistrée.'], 201);
    }
}
