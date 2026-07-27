<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Models\MindsetAssessment;
use App\Support\MindsetTemplate;
use Illuminate\Http\Request;

class MindsetProgressController extends EnterpriseController
{
    public function index(Request $request)
    {
        $employees = $this->company($request)
            ->employees()
            ->with(['assessments.responses'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json($employees->map(function ($employee) {
            $initial = $employee->assessments
                ->where('type', 'initial')
                ->sortBy('assessed_at')
                ->first();
            $followUp = $employee->assessments
                ->where('type', 'follow_up')
                ->sortByDesc('assessed_at')
                ->first();

            return [
                'employee' => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                ],
                'initial' => $this->summary($initial),
                'follow_up' => $this->summary($followUp),
                'delta' => $initial && $followUp ? $followUp->total_score - $initial->total_score : null,
                'pillars' => $initial && $followUp ? $this->pillarDeltas($initial, $followUp) : [],
            ];
        })->values());
    }

    private function summary(?MindsetAssessment $assessment): ?array
    {
        if (!$assessment) {
            return null;
        }

        return [
            'id' => $assessment->id,
            'assessed_at' => $assessment->assessed_at?->toDateString(),
            'total_score' => $assessment->total_score,
            'level' => $assessment->level,
        ];
    }

    private function pillarDeltas(MindsetAssessment $initial, MindsetAssessment $followUp): array
    {
        $initialScores = $initial->responses->groupBy('pillar')->map(fn ($responses) => (int) $responses->sum('score'));
        $followUpScores = $followUp->responses->groupBy('pillar')->map(fn ($responses) => (int) $responses->sum('score'));
        $pillars = [];

        foreach (MindsetTemplate::pillarLabels() as $key => $label) {
            $baseline = (int) ($initialScores[$key] ?? 0);
            $current = (int) ($followUpScores[$key] ?? 0);
            $pillars[] = [
                'key' => $key,
                'label' => $label,
                'initial_score' => $baseline,
                'follow_up_score' => $current,
                'delta' => $current - $baseline,
            ];
        }

        return $pillars;
    }
}
