<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Models\MindsetAssessment;
use App\Models\ProductEvent;
use App\Support\MindsetTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MindsetAssessmentController extends EnterpriseController
{
    public function template()
    {
        return response()->json(MindsetTemplate::template());
    }

    public function index(Request $request)
    {
        $company = $this->company($request);
        $query = $company->assessments()
            ->with('employee:id,first_name,last_name,job_title,department')
            ->withCount('responses')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('employee_id')) {
            $query->where('company_employee_id', $request->integer('employee_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        return $this->persist($request, $this->company($request));
    }

    public function show(Request $request, MindsetAssessment $assessment)
    {
        $assessment = $this->assessmentForCompany($this->company($request), $assessment);
        $assessment->load([
            'employee',
            'evaluator:id,name',
            'responses',
        ]);

        return response()->json([
            'assessment' => $assessment,
            'template' => $assessment->methodology_snapshot ?: MindsetTemplate::template(),
            'comparison' => $this->comparisonFor($assessment),
        ]);
    }

    public function update(Request $request, MindsetAssessment $assessment)
    {
        $company = $this->company($request);
        $assessment = $this->assessmentForCompany($company, $assessment);

        return $this->persist($request, $company, $assessment);
    }

    private function persist(Request $request, Company $company, ?MindsetAssessment $assessment = null)
    {
        $isNewAssessment = $assessment === null;
        $methodology = $assessment?->methodology_snapshot ?: MindsetTemplate::template();
        $data = $this->validated($request, $methodology);
        $employee = $company->employees()->find($data['company_employee_id']);

        if (!$employee) {
            throw ValidationException::withMessages([
                'company_employee_id' => 'Le collaborateur sélectionné n’appartient pas à votre entreprise.',
            ]);
        }

        if ($data['type'] === 'follow_up' && !$this->hasInitialAssessment($employee, $assessment, $data['assessed_at'])) {
            throw ValidationException::withMessages([
                'type' => 'Un diagnostic initial T0 daté avant cet entretien est requis pour créer le suivi.',
            ]);
        }

        [$responses, $totalScore, $level] = $this->normalizeResponses($data['responses'], $methodology);
        $actionItems = collect($data['action_items'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        $assessment = DB::transaction(function () use ($request, $company, $assessment, $data, $responses, $totalScore, $level, $actionItems) {
            $attributes = [
                'company_employee_id' => $data['company_employee_id'],
                'evaluator_id' => $assessment?->evaluator_id ?? $request->user()->id,
                'type' => $data['type'],
                'assessed_at' => $data['assessed_at'],
                'total_score' => $totalScore,
                'level' => $level['label'],
                'action_items' => $actionItems,
                'support_needs' => $data['support_needs'] ?? null,
                'next_review_at' => $data['next_review_at'] ?? null,
            ];

            if (!$assessment) {
                $methodology = MindsetTemplate::template();
                $attributes['methodology_version'] = $methodology['version'];
                $attributes['methodology_hash'] = hash('sha256', json_encode($methodology, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $attributes['methodology_snapshot'] = $methodology;
            }

            if ($assessment) {
                $assessment->update($attributes);
                $assessment->responses()->delete();
            } else {
                $assessment = $company->assessments()->create($attributes);
            }

            $assessment->responses()->createMany($responses);

            return $assessment;
        });

        $assessment->load(['employee', 'responses']);

        if ($isNewAssessment) {
            ProductEvent::record(
                'assessment_submitted',
                $request->user(),
                'assessment',
                $assessment->id,
                ['assessment_type' => $assessment->type],
                ProductEvent::idempotencyKey('assessment_submitted', [$assessment->id]),
            );
        }

        return response()->json($assessment, $request->isMethod('post') ? 201 : 200);
    }

    private function hasInitialAssessment(CompanyEmployee $employee, ?MindsetAssessment $currentAssessment, string $followUpDate): bool
    {
        return $employee->assessments()
            ->where('type', 'initial')
            ->whereDate('assessed_at', '<=', $followUpDate)
            ->when($currentAssessment, fn ($query) => $query->where('id', '!=', $currentAssessment->id))
            ->exists();
    }

    private function normalizeResponses(array $input, array $methodology): array
    {
        $questions = MindsetTemplate::questions($methodology);
        $responses = [];

        foreach ($input as $response) {
            $key = $response['question_key'];

            if (!isset($questions[$key])) {
                throw ValidationException::withMessages([
                    'responses' => 'Une question de la grille Mindset est invalide.',
                ]);
            }

            if (isset($responses[$key])) {
                throw ValidationException::withMessages([
                    'responses' => 'Chaque question de la grille doit être renseignée une seule fois.',
                ]);
            }

            $responses[$key] = [
                'question_key' => $key,
                'pillar' => $questions[$key]['pillar'],
                'score' => (int) $response['score'],
                'observation' => filled($response['observation'] ?? null) ? trim($response['observation']) : null,
            ];
        }

        $missing = array_diff(array_keys($questions), array_keys($responses));
        if ($missing) {
            throw ValidationException::withMessages([
                'responses' => 'Les 20 questions du diagnostic doivent être notées.',
            ]);
        }

        $records = array_values($responses);
        $totalScore = array_sum(array_column($records, 'score'));

        return [$records, $totalScore, MindsetTemplate::interpretationFor($totalScore, $methodology)];
    }

    private function assessmentForCompany(Company $company, MindsetAssessment $assessment): MindsetAssessment
    {
        return $company->assessments()->findOrFail($assessment->id);
    }

    private function comparisonFor(MindsetAssessment $assessment): ?array
    {
        if ($assessment->type !== 'follow_up') {
            return null;
        }

        $baseline = $assessment->employee
            ->assessments()
            ->where('type', 'initial')
            ->where('methodology_version', $assessment->methodology_version)
            ->whereDate('assessed_at', '<=', $assessment->assessed_at)
            ->orderByDesc('assessed_at')
            ->first();

        if (!$baseline) {
            return null;
        }

        $baseline->load('responses');
        $currentScores = $this->pillarScores($assessment);
        $baselineScores = $this->pillarScores($baseline);
        $baselineByKey = collect($baselineScores)->keyBy('key');

        return [
            'baseline' => $this->summaryFor($baseline),
            'follow_up' => $this->summaryFor($assessment),
            'elapsed_days' => $baseline->assessed_at->diffInDays($assessment->assessed_at),
            'delta' => $assessment->total_score - $baseline->total_score,
            'pillars' => collect($currentScores)->map(function (array $pillar) use ($baselineByKey) {
                $baselinePillar = $baselineByKey[$pillar['key']];

                return [
                    ...$pillar,
                    'baseline_score' => $baselinePillar['score'],
                    'delta' => $pillar['score'] - $baselinePillar['score'],
                ];
            })->values(),
        ];
    }

    private function summaryFor(MindsetAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'assessed_at' => $assessment->assessed_at?->toDateString(),
            'total_score' => $assessment->total_score,
            'level' => $assessment->level,
            'methodology_version' => $assessment->methodology_version,
        ];
    }

    private function pillarScores(MindsetAssessment $assessment): array
    {
        $scores = $assessment->responses
            ->groupBy('pillar')
            ->map(fn ($responses) => (int) $responses->sum('score'));

        $pillars = [];
        foreach (MindsetTemplate::pillarLabels() as $key => $label) {
            $pillars[] = [
                'key' => $key,
                'label' => $label,
                'score' => (int) ($scores[$key] ?? 0),
            ];
        }

        return $pillars;
    }

    private function validated(Request $request, array $methodology): array
    {
        return $request->validate([
            'company_employee_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['initial', 'follow_up'])],
            'assessed_at' => ['required', 'date'],
            'responses' => ['required', 'array', 'size:' . count(MindsetTemplate::questions($methodology))],
            'responses.*.question_key' => ['required', 'string', 'max:80'],
            'responses.*.score' => ['required', 'integer', 'between:1,5'],
            'responses.*.observation' => ['nullable', 'string'],
            'action_items' => ['nullable', 'array', 'max:3'],
            'action_items.*' => ['nullable', 'string', 'max:1000'],
            'support_needs' => ['nullable', 'string'],
            'next_review_at' => ['nullable', 'date'],
        ]);
    }
}
