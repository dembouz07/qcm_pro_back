<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function index(Request $request)
    {
        $query = Submission::query()
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

    public function show(Submission $submission)
    {
        return $submission->load([
            'user.schoolClass',
            'quiz.schoolClass',
            'answers.question',
            'answers.choice',
        ]);
    }
}
