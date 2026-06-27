<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\ProgressiveQuizCreator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgressiveQuizController extends Controller
{
    public function store(Request $request, ProgressiveQuizCreator $creator)
    {
        $data = $this->validatedData($request);

        $quiz = $creator->create($data, $request->user());

        return response()->json($quiz, 201);
    }

    public function update(Request $request, Quiz $quiz, ProgressiveQuizCreator $creator)
    {
        if ((int) $quiz->created_by !== (int) $request->user()->id) {
            abort(response()->json(['message' => "Ce QCM ne vous appartient pas."], 403));
        }

        if ($quiz->submissions()->exists()) {
            return response()->json([
                'message' => 'Impossible de modifier : ce diagnostic a déjà des réponses.',
            ], 409);
        }

        $data = $this->validatedData($request);
        $quiz = $creator->update($quiz, $data);

        return response()->json($quiz);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'stage_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('owner_id', $request->user()->id)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_published' => ['sometimes', 'boolean'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['nullable', 'string', 'max:190'],
            'stages.*.questions' => ['required', 'array', 'min:1'],
            'stages.*.questions.*' => ['required', 'string'],
        ]);
    }
}
