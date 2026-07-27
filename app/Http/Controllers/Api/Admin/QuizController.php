<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\User;
use App\Services\QuizCreator;
use App\Services\QuestionStatisticsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class QuizController extends Controller
{
    public function index(Request $request)
    {
        return Quiz::query()
            ->where('created_by', $request->user()->id)
            ->with('schoolClass')
            ->withCount(['questions', 'submissions'])
            ->latest('starts_at')
            ->get();
    }

    public function show(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
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
        $this->authorizeOwner($request, $quiz);

        if ($quiz->submissions()->exists()) {
            return response()->json([
                'message' => 'Impossible de modifier les questions : ce QCM a déjà des soumissions.',
            ], 409);
        }

        $data = $this->validatedData($request);
        $quiz = $creator->updateWithQuestions($quiz, $data);

        return response()->json($quiz);
    }

    public function destroy(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);

        $quiz->delete();

        return response()->json(['message' => 'QCM supprimé.']);
    }

    public function archive(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->update(['archived_at' => now()]);
        return response()->json(['message' => 'QCM archivé.', 'archived_at' => $quiz->archived_at]);
    }

    public function unarchive(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->update(['archived_at' => null]);
        return response()->json(['message' => 'QCM désarchivé.']);
    }

    public function close(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->update(['closed_at' => now()]);

        return response()->json([
            'message' => 'Le QCM est maintenant fermé.',
            'closed_at' => $quiz->closed_at,
        ]);
    }

    public function reopen(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->update(['closed_at' => null]);

        return response()->json([
            'message' => 'Le QCM est de nouveau ouvert.',
            'closed_at' => null,
        ]);
    }

    /**
     * Notifie par email les élèves de la classe du QCM (ouverture / rappel).
     */
    public function notify(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->load('schoolClass');

        if ($quiz->school_class_id === null) {
            return response()->json([
                'message' => 'Ce QCM est public et n’est associé à aucune classe.',
                'sent' => 0,
                'failed' => 0,
                'total' => 0,
            ], 422);
        }

        $students = User::where('role', 'student')
            ->where('school_class_id', $quiz->school_class_id)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        if ($students->isEmpty()) {
            return response()->json([
                'message' => 'Aucun élève avec une adresse email dans cette classe.',
                'sent' => 0,
                'failed' => 0,
                'total' => 0,
            ], 422);
        }

        $frontend = rtrim((string) config('services.paytech.frontend_url'), '/');
        $className = $quiz->schoolClass?->name ?? '';
        $opening = $quiz->starts_at ? $quiz->starts_at->format('d/m/Y à H:i') : 'bientôt';
        $subject = "QCM Pro — « {$quiz->title} » disponible";

        $sent = 0;
        $failed = 0;

        foreach ($students as $student) {
            $body = "Bonjour {$student->name},\n\n"
                . "Un nouveau QCM est programmé pour votre classe {$className} :\n"
                . "• Titre : {$quiz->title}\n"
                . "• Ouverture : {$opening}\n\n"
                . "Connectez-vous pour le passer : {$frontend}/login\n\n"
                . "— QCM Pro";

            try {
                Mail::raw($body, function ($message) use ($student, $subject) {
                    $message->to($student->email)->subject($subject);
                });
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Notification email échouée', ['email' => $student->email, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => "Notification envoyée à {$sent} élève(s)" . ($failed ? ", {$failed} échec(s)." : '.'),
            'sent' => $sent,
            'failed' => $failed,
            'total' => $students->count(),
        ]);
    }

    /**
     * Statistiques par question : combien de fois chaque question a été ratée
     * (pour améliorer les questions les plus difficiles).
     */
    public function stats(Request $request, Quiz $quiz, QuestionStatisticsCalculator $calculator)
    {
        $this->authorizeOwner($request, $quiz);
        $quiz->load('questions');

        $submissionIds = $quiz->submissions()->pluck('id');

        $answers = \App\Models\SubmissionAnswer::whereIn('submission_id', $submissionIds)
            ->get(['question_id', 'choice_id', 'selected_choice_ids', 'is_correct']);

        $questions = $calculator->calculate(
            $quiz->questions,
            $answers,
            $submissionIds->count(),
        );

        return response()->json([
            'submissions' => $submissionIds->count(),
            'questions' => $questions,
        ]);
    }

    private function authorizeOwner(Request $request, Quiz $quiz): void
    {
        if ((int) $quiz->created_by !== (int) $request->user()->id) {
            abort(response()->json(['message' => "Ce QCM ne vous appartient pas."], 403));
        }
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('owner_id', $request->user()->id)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_published' => ['sometimes', 'boolean'],
            'show_corrections' => ['sometimes', 'boolean'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.body' => ['required', 'string'],
            'questions.*.explanation' => ['nullable', 'string'],
            'questions.*.points' => ['nullable', 'integer', 'min:1', 'max:100'],
            'questions.*.choices' => ['required', 'array', 'min:2'],
            'questions.*.choices.*.body' => ['required', 'string'],
            'questions.*.choices.*.is_correct' => ['required', 'boolean'],
        ]);
    }
}
