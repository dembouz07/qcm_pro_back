<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
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

        return $submission->load([
            'user.schoolClass',
            'quiz.schoolClass',
            'answers.question',
            'answers.choice',
        ]);
    }
}
