<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SurveyController extends Controller
{
    public function index(Request $request)
    {
        return Survey::query()
            ->where('user_id', $request->user()->id)
            ->withCount('responses')
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $survey = Survey::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'questions' => $this->normalizeQuestions($data['questions']),
            'access_token' => Str::random(40),
            'is_open' => true,
        ]);

        return response()->json($survey, 201);
    }

    public function update(Request $request, Survey $survey)
    {
        $this->authorizeOwner($request, $survey);
        $data = $this->validated($request);

        $survey->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'questions' => $this->normalizeQuestions($data['questions']),
        ]);

        return response()->json($survey->fresh());
    }

    private function normalizeQuestions(array $questions): array
    {
        return collect($questions)->values()->map(function ($q, $i) {
            $type = $q['type'] ?? 'text';
            return [
                'id' => $i + 1,
                'body' => $q['body'],
                'type' => $type,
                'options' => in_array($type, ['single', 'multiple'], true) ? array_values($q['options'] ?? []) : [],
            ];
        })->all();
    }

    public function show(Request $request, Survey $survey)
    {
        $this->authorizeOwner($request, $survey);
        $survey->load(['responses' => fn ($q) => $q->latest('submitted_at')]);
        return $survey;
    }

    public function toggle(Request $request, Survey $survey)
    {
        $this->authorizeOwner($request, $survey);
        $survey->update(['is_open' => !$survey->is_open]);
        return response()->json(['is_open' => $survey->is_open]);
    }

    public function destroy(Request $request, Survey $survey)
    {
        $this->authorizeOwner($request, $survey);
        $survey->delete();
        return response()->json(['message' => 'Sondage supprimé.']);
    }

    private function authorizeOwner(Request $request, Survey $survey): void
    {
        if ((int) $survey->user_id !== (int) $request->user()->id) {
            abort(response()->json(['message' => "Ce sondage ne vous appartient pas."], 403));
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.body' => ['required', 'string', 'max:1000'],
            'questions.*.type' => ['required', Rule::in(['text', 'single', 'multiple'])],
            'questions.*.options' => ['nullable', 'array', 'max:20'],
            'questions.*.options.*' => ['string', 'max:300'],
        ]);
    }
}
