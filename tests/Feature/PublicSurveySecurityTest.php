<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicSurveySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_survey_accepts_only_complete_bounded_answers_from_declared_options(): void
    {
        $owner = User::factory()->create([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_PREMIUM,
            'subscription_status' => 'active',
            'subscribed_until' => now()->addMonth(),
        ]);

        $survey = Survey::create([
            'user_id' => $owner->id,
            'title' => 'Retour de formation',
            'questions' => [
                ['id' => 1, 'body' => 'Commentaire', 'type' => 'text', 'options' => []],
                ['id' => 2, 'body' => 'Satisfaction', 'type' => 'single', 'options' => ['Oui', 'Non']],
                ['id' => 3, 'body' => 'Thèmes', 'type' => 'multiple', 'options' => ['A', 'B']],
            ],
            'access_token' => Str::random(40),
            'is_open' => true,
        ]);

        $endpoint = "/api/public/surveys/{$survey->access_token}/respond";

        $this->postJson($endpoint, [
            'answers' => ['1' => 'Utile', '2' => 'Peut-être', '3' => ['A']],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.2');

        $this->postJson($endpoint, [
            'answers' => ['1' => str_repeat('x', 2001), '2' => 'Oui', '3' => ['A', 'A']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['answers.1', 'answers.3']);

        $this->postJson($endpoint, [
            'answers' => ['1' => 'Utile', '2' => 'Oui'],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.3');

        $this->postJson($endpoint, [
            'answers' => ['1' => 'Utile', '2' => 'Oui', '3' => ['A', 'B']],
        ])->assertCreated();

        $this->assertDatabaseCount('survey_responses', 1);
    }
}
