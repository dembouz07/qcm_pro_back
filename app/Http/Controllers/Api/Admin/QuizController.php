<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\QuizCreator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuizController extends Controller
{
    public function index()
    {
        return Quiz::query()
            ->with('schoolClass')
            ->withCount(['questions', 'submissions'])
            ->latest('starts_at')
            ->get();
    }

    public function show(Quiz $quiz)
    {
        $quiz->load('schoolClass', 'questions.choices');
        $quiz->public_link = $quiz->access_token
            ? url("/api/public/quiz/{$quiz->access_token}")
            : null;
        return $quiz;
    }

    public function store(Request $request, QuizCreator $creator)
    {
        $data = $this->validatedData($request);

        $quiz = $creator->createWithQuestions($data, $request->user());

        return response()->json($quiz, 201);
    }

    public function update(Request $request, Quiz $quiz, QuizCreator $creator)
    {
        if ($quiz->submissions()->exists()) {
            return response()->json([
                'message' => 'Impossible de modifier les questions : ce QCM a déjà des soumissions.',
            ], 409);
        }

        $data = $this->validatedData($request);
        $quiz = $creator->updateWithQuestions($quiz, $data);

        return response()->json($quiz);
    }

    public function destroy(Quiz $quiz)
    {
        $quiz->delete();

        return response()->json(['message' => 'QCM supprimé.']);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_published' => ['sometimes', 'boolean'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.body' => ['required', 'string'],
            'questions.*.points' => ['nullable', 'integer', 'min:1', 'max:100'],
            'questions.*.choices' => ['required', 'array', 'min:2'],
            'questions.*.choices.*.body' => ['required', 'string'],
            'questions.*.choices.*.is_correct' => ['required', 'boolean'],
        ]);
    }
}
