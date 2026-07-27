<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Models\CompanyEmployee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyEmployeeController extends EnterpriseController
{
    public function index(Request $request)
    {
        $employees = $this->company($request)
            ->employees()
            ->with([
                'latestAssessment:id,company_employee_id,type,total_score,level,assessed_at',
            ])
            ->withCount('assessments')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $employee = $this->company($request)->employees()->create($this->validated($request));

        return response()->json($employee, 201);
    }

    public function show(Request $request, CompanyEmployee $employee)
    {
        $employee = $this->employeeForCompany($request, $employee);
        $employee->load([
            'latestAssessment:id,company_employee_id,type,total_score,level,assessed_at',
        ])->loadCount('assessments');

        return response()->json($employee);
    }

    public function update(Request $request, CompanyEmployee $employee)
    {
        $employee = $this->employeeForCompany($request, $employee);
        $employee->update($this->validated($request, $employee));

        return response()->json($employee->fresh());
    }

    public function destroy(Request $request, CompanyEmployee $employee)
    {
        $employee = $this->employeeForCompany($request, $employee);

        if ($employee->assessments()->exists()) {
            return response()->json([
                'message' => 'Ce collaborateur possède des diagnostics. Conservez son historique ou supprimez les diagnostics avant de le retirer.',
            ], 422);
        }

        $employee->delete();

        return response()->json(['message' => 'Collaborateur retiré.']);
    }

    private function employeeForCompany(Request $request, CompanyEmployee $employee): CompanyEmployee
    {
        return $this->company($request)
            ->employees()
            ->findOrFail($employee->id);
    }

    private function validated(Request $request, ?CompanyEmployee $employee = null): array
    {
        $emailRule = Rule::unique('company_employees', 'email')
            ->where('company_id', $this->company($request)->id);

        if ($employee) {
            $emailRule->ignore($employee->id);
        }

        return $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', $emailRule],
            'job_title' => ['nullable', 'string', 'max:190'],
            'department' => ['nullable', 'string', 'max:190'],
            'seniority_months' => ['nullable', 'integer', 'min:0', 'max:720'],
        ]);
    }
}
