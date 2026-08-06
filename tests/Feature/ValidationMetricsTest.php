<?php

namespace Tests\Feature;

use App\Models\ProductEvent;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ValidationMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_exposes_mature_cohorts_with_raw_denominators(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        config()->set('analytics.metric_environment', 'testing');
        config()->set('analytics.test_started_at', null);

        $firstRegistration = now()->subDays(45);
        $secondRegistration = now()->subDays(40);

        $this->event('registration-a', 'account_registered', 'actor-a', $firstRegistration, 'admin');
        $this->event('created-a', 'quiz_created', 'actor-a', $firstRegistration->copy()->addSeconds(30), 'admin', 'quiz-a');
        $this->event('published-a', 'quiz_published', 'actor-a', $firstRegistration->copy()->addMinute(), 'admin', 'quiz-a');
        $this->event('retained-a', 'report_viewed', 'actor-a', $firstRegistration->copy()->addDays(30), 'admin');

        $this->event('registration-b', 'account_registered', 'actor-b', $secondRegistration, 'admin');
        $this->event('created-b', 'quiz_created', 'actor-b', $secondRegistration->copy()->addMinute(), 'admin', 'quiz-b');
        $this->event('published-b', 'quiz_published', 'actor-b', $secondRegistration->copy()->addMinutes(100), 'admin', 'quiz-b');
        $this->event('retained-b', 'quiz_created', 'actor-b', $secondRegistration->copy()->addDays(30), 'admin', 'quiz-c');

        $this->event('internal-registration', 'account_registered', 'actor-internal', now()->subDays(50), 'admin', null, true);

        QuizAttempt::create([
            'id' => (string) Str::uuid(),
            'channel' => 'public_link',
            'environment' => 'testing',
            'is_internal' => false,
            'started_at' => now()->subDays(2),
            'matures_at' => now()->subDay(),
            'submitted_at' => now()->subDays(2)->addMinutes(5),
            'submission_mode' => 'manual',
            'is_valid_completion' => true,
        ]);
        QuizAttempt::create([
            'id' => (string) Str::uuid(),
            'channel' => 'public_link',
            'environment' => 'testing',
            'is_internal' => false,
            'started_at' => now()->subDays(2),
            'matures_at' => now()->subDay(),
            'submitted_at' => now(),
            'submission_mode' => 'manual',
            'is_valid_completion' => true,
        ]);
        QuizAttempt::create([
            'id' => (string) Str::uuid(),
            'channel' => 'public_link',
            'environment' => 'testing',
            'is_internal' => false,
            'started_at' => now()->subDays(2),
            'matures_at' => now()->subDay(),
            'submitted_at' => now()->subDays(2)->addMinutes(10),
            'submission_mode' => 'automatic',
            'is_valid_completion' => false,
            'invalid_reason' => 'automatic_incomplete',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->getJson('/api/superadmin/validation-metrics')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('activation.created_and_published_within_7_days.numerator', 2)
            ->assertJsonPath('activation.created_and_published_within_7_days.denominator', 2)
            ->assertJsonPath('activation.registration_to_first_publication_minutes.median', 50.5)
            ->assertJsonPath('public_quiz_completion.numerator', 1)
            ->assertJsonPath('public_quiz_completion.denominator', 3)
            ->assertJsonPath('public_quiz_completion.rate_percent', 33.3)
            ->assertJsonPath('public_quiz_completion.invalid_submissions', 1)
            ->assertJsonPath('public_quiz_completion.late_submissions', 1)
            ->assertJsonPath('retention.numerator', 2)
            ->assertJsonPath('retention.denominator', 2);
    }

    public function test_public_funnel_events_are_deduplicated_per_browser_session(): void
    {
        config()->set('analytics.hash_key', 'test-analytics-key');
        $visitorId = (string) Str::uuid();
        $payload = [
            'event' => 'demo_booking_clicked',
            'source' => 'pricing',
            'visitor_id' => $visitorId,
        ];

        $this->postJson('/api/public/events', $payload)->assertNoContent();
        $this->postJson('/api/public/events', $payload)->assertNoContent();

        $this->assertDatabaseCount('product_events', 1);
        $this->assertDatabaseHas('product_events', [
            'event' => 'demo_booking_clicked',
            'metadata' => json_encode(['source' => 'pricing']),
        ]);

        $this->postJson('/api/public/events', [
            ...$payload,
            'source' => 'untrusted-free-text',
        ])->assertUnprocessable()->assertJsonValidationErrors('source');

        $this->postJson('/api/public/events', [
            'event' => 'demo_booking_clicked',
            'source' => 'pricing',
        ])->assertUnprocessable()->assertJsonValidationErrors('visitor_id');
    }

    private function event(
        string $key,
        string $event,
        string $actor,
        Carbon $occurredAt,
        ?string $role = null,
        ?string $subject = null,
        bool $internal = false,
    ): void {
        ProductEvent::create([
            'idempotency_key' => $key,
            'event' => $event,
            'actor_key' => str_pad($actor, 64, '0'),
            'account_role' => $role,
            'subject_type' => $subject ? 'quiz' : null,
            'subject_key' => $subject ? str_pad($subject, 64, '0') : null,
            'environment' => 'testing',
            'is_internal' => $internal,
            'schema_version' => 1,
            'occurred_at' => $occurredAt,
        ]);
    }
}
