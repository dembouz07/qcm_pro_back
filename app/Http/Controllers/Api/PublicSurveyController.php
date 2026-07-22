<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use Illuminate\Http\Request;

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
            'answers' => ['required', 'array'],
        ]);

        // On ne conserve que les réponses correspondant à des questions existantes.
        $validIds = collect($survey->questions)->pluck('id')->map(fn ($i) => (string) $i)->all();
        $answers = [];
        foreach ($data['answers'] as $qid => $value) {
            if (in_array((string) $qid, $validIds, true)) {
                $answers[(string) $qid] = $value;
            }
        }

        $survey->responses()->create([
            'answers' => $answers,
            'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Merci, votre réponse a été enregistrée.'], 201);
    }
}
