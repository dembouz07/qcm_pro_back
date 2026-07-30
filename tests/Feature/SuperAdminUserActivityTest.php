<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Models\MindsetAssessment;
use App\Models\Quiz;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminUserActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainer_activity_counts_owned_quizzes_and_received_results(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $trainer = User::factory()->create(['role' => 'admin']);
        $class = SchoolClass::create([
            'name' => 'Classe activité',
            'code' => 'ACTIVITY',
            'owner_id' => $trainer->id,
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'school_class_id' => $class->id,
        ]);
        $quiz = Quiz::create([
            'title' => 'QCM activité',
            'school_class_id' => $class->id,
            'created_by' => $trainer->id,
            'is_published' => true,
        ]);
        Submission::create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'score' => 8,
            'total_points' => 10,
            'percentage' => 80,
            'note_sur_20' => 16,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/superadmin/users?role=admin')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $trainer->id)
            ->assertJsonPath('data.0.quizzes_count', 1)
            ->assertJsonPath('data.0.received_submissions_count', 1)
            ->assertJsonPath('data.0.submissions_count', 0);
    }

    public function test_enterprise_activity_counts_completed_diagnostics(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $enterprise = User::factory()->create(['role' => 'enterprise']);
        $company = Company::create([
            'owner_id' => $enterprise->id,
            'name' => 'Entreprise activité',
        ]);
        $employee = CompanyEmployee::create([
            'company_id' => $company->id,
            'first_name' => 'Awa',
            'last_name' => 'Diop',
        ]);
        MindsetAssessment::create([
            'company_id' => $company->id,
            'company_employee_id' => $employee->id,
            'evaluator_id' => $enterprise->id,
            'type' => 'initial',
            'assessed_at' => now()->toDateString(),
            'total_score' => 72,
            'level' => 'Autonome',
        ]);

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/superadmin/users?role=enterprise')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enterprise->id)
            ->assertJsonPath('data.0.mindset_assessments_count', 1);
    }
}
