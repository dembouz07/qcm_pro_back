<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolClassAcademicYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainer_can_manage_a_class_with_its_academic_year(): void
    {
        $trainer = $this->actingAsTrainer();

        $created = $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
            'code' => 'TA2627',
        ])->assertCreated()
            ->assertJsonPath('name', 'Terminale A')
            ->assertJsonPath('academic_year', '2026-2027')
            ->assertJsonPath('owner_id', $trainer->id);

        $classId = $created->json('id');

        $this->getJson("/api/admin/classes/{$classId}")
            ->assertOk()
            ->assertJsonPath('academic_year', '2026-2027');

        $this->putJson("/api/admin/classes/{$classId}", [
            'name' => 'Terminale A actualisee',
            'code' => 'TA2627',
        ])->assertOk()
            ->assertJsonPath('name', 'Terminale A actualisee')
            ->assertJsonPath('academic_year', '2026-2027');

        $this->assertDatabaseHas('school_classes', [
            'id' => $classId,
            'owner_id' => $trainer->id,
            'name' => 'Terminale A actualisee',
            'academic_year' => '2026-2027',
        ]);

        $this->deleteJson("/api/admin/classes/{$classId}")->assertOk();
        $this->assertDatabaseMissing('school_classes', ['id' => $classId]);
    }

    public function test_same_class_name_is_allowed_for_different_academic_years(): void
    {
        $trainer = $this->actingAsTrainer();

        $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2025-2026',
            'code' => 'TA2526',
        ])->assertCreated();

        $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
            'code' => 'TA2627',
        ])->assertCreated();

        $this->assertDatabaseCount('school_classes', 2);
        $this->assertDatabaseHas('school_classes', [
            'owner_id' => $trainer->id,
            'name' => 'Terminale A',
            'academic_year' => '2025-2026',
        ]);
        $this->assertDatabaseHas('school_classes', [
            'owner_id' => $trainer->id,
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
        ]);
    }

    public function test_duplicate_name_is_rejected_only_for_the_same_owner_and_academic_year(): void
    {
        $firstTrainer = $this->actingAsTrainer();

        $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
            'code' => 'FIRST26',
        ])->assertCreated();

        $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
            'code' => 'SECOND26',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('school_classes', 1);

        $secondTrainer = $this->actingAsTrainer();

        $this->postJson('/api/admin/classes', [
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
            'code' => 'OTHER26',
        ])->assertCreated();

        $this->assertDatabaseHas('school_classes', [
            'owner_id' => $firstTrainer->id,
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
        ]);
        $this->assertDatabaseHas('school_classes', [
            'owner_id' => $secondTrainer->id,
            'name' => 'Terminale A',
            'academic_year' => '2026-2027',
        ]);
    }

    public function test_academic_year_must_use_consecutive_four_digit_years(): void
    {
        $this->actingAsTrainer();

        foreach (['2026', '2026/2027', '26-27', '2026-2028', '2027-2026', 'abcd-efgh'] as $academicYear) {
            $this->postJson('/api/admin/classes', [
                'name' => 'Classe invalide',
                'academic_year' => $academicYear,
                'code' => 'INVALID',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('academic_year');
        }

        $this->assertDatabaseCount('school_classes', 0);
    }

    public function test_missing_academic_year_defaults_to_the_current_school_cycle(): void
    {
        $this->travelTo(Carbon::parse('2026-07-31 12:00:00'));
        $this->actingAsTrainer();

        $this->postJson('/api/admin/classes', [
            'name' => 'Classe juillet',
            'code' => 'JULY26',
        ])->assertCreated()
            ->assertJsonPath('academic_year', '2025-2026');

        $this->travelTo(Carbon::parse('2026-08-01 12:00:00'));

        $this->postJson('/api/admin/classes', [
            'name' => 'Classe aout',
            'code' => 'AUG26',
        ])->assertCreated()
            ->assertJsonPath('academic_year', '2026-2027');

        $this->travelBack();
    }

    public function test_classes_can_be_filtered_by_academic_year_and_are_sorted_newest_first(): void
    {
        $trainer = $this->actingAsTrainer();

        $olderZeta = $this->createClass($trainer, 'Zeta', '2026-2027', 'ZETA26');
        $newest = $this->createClass($trainer, 'Beta', '2027-2028', 'BETA27');
        $olderAlpha = $this->createClass($trainer, 'Alpha', '2026-2027', 'ALPHA26');

        $otherTrainer = User::factory()->create(['role' => 'admin']);
        $this->createClass($otherTrainer, 'Classe externe', '2028-2029', 'OTHER28');

        $this->getJson('/api/admin/classes')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.id', $newest->id)
            ->assertJsonPath('1.id', $olderAlpha->id)
            ->assertJsonPath('2.id', $olderZeta->id);

        $this->getJson('/api/admin/classes?academic_year=2026-2027')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $olderAlpha->id)
            ->assertJsonPath('1.id', $olderZeta->id);

        $this->getJson('/api/admin/classes?academic_year=2026-2028')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_year');
    }

    public function test_class_codes_enroll_students_in_the_exact_annual_cohort(): void
    {
        $trainer = User::factory()->create(['role' => 'admin']);
        $olderClass = $this->createClass($trainer, 'Terminale A', '2025-2026', 'TA2526');
        $newerClass = $this->createClass($trainer, 'Terminale A', '2026-2027', 'TA2627');

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withSession([]);

        $this->postJson('/api/auth/register', [
            'name' => 'Eleve 2025',
            'email' => 'eleve.2025@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'class_code' => ' ta2526 ',
        ])->assertCreated()
            ->assertJsonPath('user.school_class_id', $olderClass->id)
            ->assertJsonPath('user.school_class.academic_year', '2025-2026');

        $this->postJson('/api/auth/register', [
            'name' => 'Eleve 2026',
            'email' => 'eleve.2026@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'class_code' => 'TA2627',
        ])->assertCreated()
            ->assertJsonPath('user.school_class_id', $newerClass->id)
            ->assertJsonPath('user.school_class.academic_year', '2026-2027');

        $this->assertDatabaseHas('users', [
            'email' => 'eleve.2025@example.com',
            'school_class_id' => $olderClass->id,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'eleve.2026@example.com',
            'school_class_id' => $newerClass->id,
        ]);
    }

    private function actingAsTrainer(): User
    {
        $trainer = User::factory()->create([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_PREMIUM,
            'subscription_status' => 'active',
            'subscribed_until' => now()->addYear(),
        ]);

        Sanctum::actingAs($trainer);

        return $trainer;
    }

    private function createClass(
        User $trainer,
        string $name,
        string $academicYear,
        string $code
    ): SchoolClass {
        return SchoolClass::create([
            'owner_id' => $trainer->id,
            'name' => $name,
            'academic_year' => $academicYear,
            'code' => $code,
        ]);
    }
}
