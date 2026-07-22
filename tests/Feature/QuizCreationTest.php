<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'subscription_status' => 'active',
            'subscribed_until' => now()->addMonth(),
        ]);
        $this->class = SchoolClass::create([
            'name' => 'Classe de test',
            'code' => 'TEST01',
            'owner_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_admin_can_create_a_manual_quiz_with_multiple_correct_answers(): void
    {
        $response = $this->postJson('/api/admin/quizzes', [
            ...$this->quizMetadata(),
            'show_corrections' => true,
            'questions' => [[
                'body' => 'Quels langages sont utilisés dans le navigateur ?',
                'points' => 2,
                'explanation' => 'JavaScript et HTML sont interprétés par le navigateur.',
                'choices' => [
                    ['body' => 'JavaScript', 'is_correct' => true],
                    ['body' => 'HTML', 'is_correct' => true],
                    ['body' => 'PHP', 'is_correct' => false],
                ],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'QCM de test')
            ->assertJsonCount(1, 'questions')
            ->assertJsonCount(3, 'questions.0.choices');

        $this->assertDatabaseHas('quizzes', [
            'title' => 'QCM de test',
            'created_by' => $this->admin->id,
            'show_corrections' => true,
        ]);
    }

    public function test_admin_can_import_a_bom_csv_and_keep_multiple_correct_answers(): void
    {
        $csv = "\xEF\xBB\xBFquestion;choice;is_correct;points\n"
            . "Quels langages sont web ?;JavaScript;oui;2\n"
            . "Quels langages sont web ?;HTML;oui;2\n"
            . "Quels langages sont web ?;Cobol;non;2\n";

        $response = $this->post('/api/admin/quizzes/import', [
            ...$this->quizMetadata(),
            'show_corrections' => '1',
            'file' => UploadedFile::fake()->createWithContent('questions.csv', $csv),
        ]);

        $response->assertCreated()
            ->assertJsonPath('show_corrections', true)
            ->assertJsonCount(1, 'questions')
            ->assertJsonCount(3, 'questions.0.choices');

        $quiz = Quiz::query()->latest('id')->with('questions.choices')->firstOrFail();
        $this->assertCount(2, $quiz->questions->first()->choices->where('is_correct', true));
    }

    public function test_progressive_quiz_keeps_stage_names_and_reachable_threshold(): void
    {
        $response = $this->postJson('/api/admin/progressive-quizzes', [
            ...$this->progressiveQuizMetadata(),
            'stage_threshold' => 2,
            'require_stage_pass' => false,
            'stages' => [
                ['name' => 'Fondations', 'questions' => ['Processus documentés ?', 'Rôles définis ?']],
                ['name' => 'Maîtrise', 'questions' => ['Indicateurs suivis ?', 'Amélioration continue ?']],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'progressive')
            ->assertJsonPath('school_class_id', null)
            ->assertJsonPath('require_stage_pass', false)
            ->assertJsonCount(4, 'questions');

        $this->assertDatabaseHas('questions', ['stage' => 1, 'stage_name' => 'Fondations']);
        $this->assertDatabaseHas('questions', ['stage' => 2, 'stage_name' => 'Maîtrise']);

        $quizId = $response->json('id');
        $this->putJson("/api/admin/progressive-quizzes/{$quizId}", [
            ...$this->progressiveQuizMetadata(),
            'stage_threshold' => 1,
            'require_stage_pass' => true,
            'stages' => [
                ['name' => 'Nouveau stade', 'questions' => ['Question mise à jour ?']],
            ],
        ])->assertOk()->assertJsonCount(1, 'questions');

        $this->assertDatabaseHas('questions', [
            'quiz_id' => $quizId,
            'stage_name' => 'Nouveau stade',
            'body' => 'Question mise à jour ?',
        ]);
    }

    public function test_progressive_quiz_rejects_a_threshold_higher_than_a_stage_question_count(): void
    {
        $response = $this->postJson('/api/admin/progressive-quizzes', [
            ...$this->progressiveQuizMetadata(),
            'stage_threshold' => 2,
            'stages' => [
                ['name' => 'Stade court', 'questions' => ['Une seule question ?']],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('stages.0.questions');
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_public_progressive_quiz_only_requires_name_and_first_name(): void
    {
        $response = $this->postJson('/api/admin/progressive-quizzes', [
            ...$this->progressiveQuizMetadata(),
            'stage_threshold' => 1,
            'require_stage_pass' => false,
            'stages' => [
                ['name' => 'Découverte', 'questions' => ['Stade 1 validé ?']],
                ['name' => 'Confirmation', 'questions' => ['Stade 2 validé ?']],
            ],
        ])->assertCreated();

        $quiz = Quiz::findOrFail($response->json('id'));
        $this->assertNull($quiz->starts_at);
        $this->assertNull($quiz->ends_at);

        $this->getJson("/api/public/quiz/{$quiz->access_token}")
            ->assertOk()
            ->assertJsonPath('is_open', true)
            ->assertJsonPath('is_locked', false)
            ->assertJsonPath('is_closed', false);

        $this->postJson("/api/admin/quizzes/{$quiz->id}/close")
            ->assertOk()
            ->assertJsonStructure(['closed_at']);

        $this->getJson("/api/public/quiz/{$quiz->access_token}")
            ->assertOk()
            ->assertJsonPath('is_open', false)
            ->assertJsonPath('is_closed', true);

        $this->postJson("/api/public/quiz/{$quiz->access_token}/start", [
            'nom' => 'Diallo',
            'prenom' => 'Awa',
        ])->assertForbidden();

        $this->postJson("/api/admin/quizzes/{$quiz->id}/reopen")
            ->assertOk()
            ->assertJsonPath('closed_at', null);

        $started = $this->postJson("/api/public/quiz/{$quiz->access_token}/start", [
            'nom' => 'Diallo',
            'prenom' => 'Awa',
        ])->assertOk()
            ->assertJsonPath('require_stage_pass', false)
            ->assertJsonCount(2, 'stages');

        $stages = $started->json('stages');
        $firstQuestion = $stages[0]['questions'][0];
        $secondQuestion = $stages[1]['questions'][0];
        $firstNo = collect($firstQuestion['choices'])->firstWhere('is_oui', false);
        $secondYes = collect($secondQuestion['choices'])->firstWhere('is_oui', true);

        $payload = [
            'nom' => 'Diallo',
            'prenom' => 'Awa',
            'answers' => [
                ['question_id' => $firstQuestion['id'], 'choice_id' => $firstNo['id']],
                ['question_id' => $secondQuestion['id'], 'choice_id' => $secondYes['id']],
            ],
        ];

        $this->postJson("/api/public/quiz/{$quiz->access_token}/submit", $payload)
            ->assertCreated()
            ->assertJsonPath('stade_atteint', 2);

        $this->assertDatabaseHas('submissions', [
            'quiz_id' => $quiz->id,
            'participant_nom' => 'Diallo',
            'participant_prenom' => 'Awa',
            'participant_referentiel' => null,
            'stade_atteint' => 2,
        ]);

        $quiz->update(['require_stage_pass' => true]);
        $payload['nom'] = 'Ndiaye';

        $this->postJson("/api/public/quiz/{$quiz->access_token}/submit", $payload)
            ->assertCreated()
            ->assertJsonPath('stade_atteint', 1);
    }

    public function test_quiz_cannot_use_another_admin_class(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $otherClass = SchoolClass::create([
            'name' => 'Classe étrangère',
            'code' => 'OTHER1',
            'owner_id' => $otherAdmin->id,
        ]);

        $response = $this->postJson('/api/admin/quizzes', [
            ...$this->quizMetadata(),
            'school_class_id' => $otherClass->id,
            'questions' => [[
                'body' => 'Question ?',
                'choices' => [
                    ['body' => 'Oui', 'is_correct' => true],
                    ['body' => 'Non', 'is_correct' => false],
                ],
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('school_class_id');
    }

    private function quizMetadata(): array
    {
        return [
            'title' => 'QCM de test',
            'description' => 'Créé par les tests automatisés.',
            'school_class_id' => $this->class->id,
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
        ];
    }

    private function progressiveQuizMetadata(): array
    {
        return [
            'title' => 'Diagnostic public',
            'description' => 'Créé par les tests automatisés.',
        ];
    }
}
