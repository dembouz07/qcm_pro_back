<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudentResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainer_can_see_all_results_for_a_student_in_their_class(): void
    {
        [$trainer, $class, $student] = $this->trainerWithStudent('A');
        [$otherTrainer, $otherClass] = $this->trainerWithStudent('B');

        $ownedQuiz = $this->quiz($trainer, $class, 'Mathématiques');
        $otherQuiz = $this->quiz($otherTrainer, $otherClass, 'Quiz externe');

        Submission::create([
            'user_id' => $student->id,
            'quiz_id' => $ownedQuiz->id,
            'score' => 8,
            'total_points' => 10,
            'percentage' => 80,
            'note_sur_20' => 16,
            'submitted_at' => now(),
        ]);
        Submission::create([
            'user_id' => $student->id,
            'quiz_id' => $otherQuiz->id,
            'score' => 10,
            'total_points' => 10,
            'percentage' => 100,
            'note_sur_20' => 20,
            'submitted_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($trainer);

        $this->getJson("/api/admin/students/{$student->id}/results")
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.class.id', $class->id)
            ->assertJsonPath('stats.submissions_count', 1)
            ->assertJsonPath('stats.average_note', 16)
            ->assertJsonPath('stats.best_note', 16)
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.quiz.title', 'Mathématiques');
    }

    public function test_trainer_cannot_see_results_for_a_student_from_another_class_owner(): void
    {
        [$trainer] = $this->trainerWithStudent('A');
        [, , $otherStudent] = $this->trainerWithStudent('B');

        Sanctum::actingAs($trainer);

        $this->getJson("/api/admin/students/{$otherStudent->id}/results")
            ->assertForbidden()
            ->assertJsonPath('message', 'Cet élève ne fait pas partie de vos classes.');
    }

    private function trainerWithStudent(string $suffix): array
    {
        $trainer = User::factory()->create([
            'role' => 'admin',
            'subscription_status' => 'active',
            'subscribed_until' => now()->addMonth(),
        ]);
        $class = SchoolClass::create([
            'name' => "Classe {$suffix}",
            'code' => "CLASS{$suffix}",
            'owner_id' => $trainer->id,
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'school_class_id' => $class->id,
        ]);

        return [$trainer, $class, $student];
    }

    private function quiz(User $trainer, SchoolClass $class, string $title): Quiz
    {
        return Quiz::create([
            'title' => $title,
            'school_class_id' => $class->id,
            'created_by' => $trainer->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_published' => true,
        ]);
    }
}
