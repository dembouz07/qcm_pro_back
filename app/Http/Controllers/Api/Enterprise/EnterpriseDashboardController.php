<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Models\MindsetAssessmentResponse;
use App\Support\MindsetTemplate;
use Illuminate\Http\Request;

class EnterpriseDashboardController extends EnterpriseController
{
    public function index(Request $request)
    {
        $company = $this->company($request);
        $assessments = $company->assessments();
        $followedEmployeeIds = (clone $assessments)
            ->where('type', 'follow_up')
            ->pluck('company_employee_id');

        $followUpsDue = (clone $assessments)
            ->where('type', 'initial')
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', now()->toDateString())
            ->whereNotIn('company_employee_id', $followedEmployeeIds)
            ->count();

        $pillarAverages = MindsetAssessmentResponse::query()
            ->whereIn('mindset_assessment_id', $company->assessments()->select('id'))
            ->selectRaw('pillar, AVG(score) as average_score')
            ->groupBy('pillar')
            ->pluck('average_score', 'pillar');

        $pillars = [];
        foreach (MindsetTemplate::pillarLabels() as $key => $label) {
            $pillars[] = [
                'key' => $key,
                'label' => $label,
                'average_score' => $pillarAverages->has($key) ? round((float) $pillarAverages[$key], 1) : null,
            ];
        }

        $recentAssessments = $company->assessments()
            ->with('employee:id,first_name,last_name,job_title')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->take(5)
            ->get();

        return response()->json([
            'company' => [
                'name' => $company->name,
                'industry' => $company->industry,
            ],
            'stats' => [
                'employees' => $company->employees()->count(),
                'assessments' => $company->assessments()->count(),
                'average_score' => round((float) ($company->assessments()->avg('total_score') ?? 0), 1),
                'follow_ups_due' => $followUpsDue,
            ],
            'pillars' => $pillars,
            'recent_assessments' => $recentAssessments,
        ]);
    }
}
