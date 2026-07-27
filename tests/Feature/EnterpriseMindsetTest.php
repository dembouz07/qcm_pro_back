<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Models\User;
use App\Support\MindsetTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseMindsetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'enterprise',
            'subscription_plan' => User::PLAN_ENTERPRISE,
            'subscription_status' => 'active',
            'subscribed_until' => now()->addMonth(),
        ]);
        $this->company = Company::create([
            'owner_id' => $this->owner->id,
            'name' => 'Techco Test',
        ]);

        Sanctum::actingAs($this->owner);
    }

    public function test_enterprise_can_create_an_employee_and_a_complete_initial_diagnostic(): void
    {
        $employee = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Awa',
            'last_name' => 'Diop',
            'email' => 'awa@example.test',
            'job_title' => 'Chargée de clientèle',
            'department' => 'Commercial',
            'seniority_months' => 18,
        ])->assertCreated();

        $assessment = $this->postJson('/api/enterprise/assessments', [
            'company_employee_id' => $employee->json('id'),
            'type' => 'initial',
            'assessed_at' => '2026-07-25',
            'responses' => $this->responses(),
            'action_items' => ['Préparer une initiative client chaque semaine'],
            'support_needs' => 'Un point hebdomadaire avec le manager.',
            'next_review_at' => '2027-01-25',
        ]);

        $assessment->assertCreated()
            ->assertJsonPath('total_score', 60)
            ->assertJsonPath('level', 'Mindset en construction')
            ->assertJsonCount(20, 'responses');

        $this->assertDatabaseHas('mindset_assessments', [
            'company_id' => $this->company->id,
            'company_employee_id' => $employee->json('id'),
            'type' => 'initial',
            'total_score' => 60,
        ]);

        $this->getJson('/api/enterprise/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.employees', 1)
            ->assertJsonPath('stats.assessments', 1)
            ->assertJsonPath('stats.average_score', 60);
    }

    public function test_follow_up_requires_an_initial_diagnostic_for_the_same_employee(): void
    {
        $employee = CompanyEmployee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Moussa',
            'last_name' => 'Fall',
        ]);

        $this->postJson('/api/enterprise/assessments', [
            'company_employee_id' => $employee->id,
            'type' => 'follow_up',
            'assessed_at' => '2027-01-25',
            'responses' => $this->responses(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    private function responses(): array
    {
        return collect(array_keys(MindsetTemplate::questions()))
            ->values()
            ->map(fn (string $questionKey, int $index) => [
                'question_key' => $questionKey,
                'score' => ($index % 5) + 1,
                'observation' => 'Observation de test.',
            ])
            ->all();
    }
}
