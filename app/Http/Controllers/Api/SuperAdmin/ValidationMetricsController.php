<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ProductEvent;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ValidationMetricsController extends Controller
{
    private const USEFUL_RETENTION_EVENTS = [
        'quiz_created',
        'quiz_published',
        'report_viewed',
        'report_exported',
    ];

    public function index(): JsonResponse
    {
        $end = now();
        $rollingStart = $end->copy()->subDays(90);
        $configuredStart = $this->configuredStart();
        $start = $configuredStart && $configuredStart->gt($rollingStart)
            ? $configuredStart
            : $rollingStart;

        if ($start->gt($end)) {
            $start = $end->copy();
        }

        $environment = (string) config('analytics.metric_environment', 'production');
        $events = ProductEvent::query()
            ->where('environment', $environment)
            ->where('is_internal', false)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();

        $registrations = $events
            ->where('event', 'account_registered')
            ->where('account_role', 'admin')
            ->whereNotNull('actor_key')
            ->unique('actor_key')
            ->values();

        $eventsByActor = $events
            ->whereNotNull('actor_key')
            ->groupBy('actor_key');

        $activation = $this->activationMetrics($registrations, $eventsByActor, $end);
        $completion = $this->completionMetrics($start, $end, $environment);
        $retention = $this->retentionMetrics($activation['activated_accounts'], $eventsByActor, $end);

        $instrumentationStartedAt = $configuredStart
            ?: $events->first()?->occurred_at
            ?: QuizAttempt::query()
                ->where('environment', $environment)
                ->where('is_internal', false)
                ->min('started_at');

        return response()->json([
            'metric_version' => (string) config('analytics.metric_version', '1.0'),
            'scope' => [
                'environment' => $environment,
                'internal_accounts_excluded' => true,
                'period_start' => $start->toIso8601String(),
                'period_end' => $end->toIso8601String(),
                'instrumentation_started_at' => $instrumentationStartedAt
                    ? Carbon::parse($instrumentationStartedAt)->toIso8601String()
                    : null,
                'timezone' => (string) config('app.timezone'),
            ],
            'segmentation' => [
                'trainer_role' => 'admin',
                'independent_vs_training_center' => 'not_available_until_organization_model_exists',
            ],
            'activation' => $activation['metrics'],
            'public_quiz_completion' => $completion,
            'retention' => $retention,
            'engagement' => [
                'demo_completions' => $events->where('event', 'demo_completed')->count(),
                'demo_booking_clicks' => $events->where('event', 'demo_booking_clicked')->count(),
                'pilot_interest_clicks' => $events->where('event', 'pilot_interest_clicked')->count(),
                'contact_clicks' => $events->where('event', 'contact_clicked')->count(),
                'assessment_starts' => $events->where('event', 'assessment_started')->count(),
                'assessment_submissions' => $events->where('event', 'assessment_submitted')->count(),
            ],
            'commercial' => [
                'source' => 'manual_until_crm_is_connected',
                'trainer_interviews' => null,
                'training_center_interviews' => null,
                'hr_interviews' => null,
                'live_demonstrations' => null,
                'paid_pilots' => null,
                'annual_contract_conversion' => null,
            ],
            'notes' => [
                'Les taux exposent toujours leur numérateur et leur dénominateur.',
                'Les comptes inscrits depuis moins de 7 jours ne sont pas inclus dans l’activation.',
                'La rétention J30 utilise la fenêtre J25 inclus à J36 exclu et uniquement les cohortes ayant 36 jours de recul.',
                'Une tentative entre au dénominateur à la fermeture du QCM, ou après 24 heures lorsqu’aucune fermeture n’est planifiée.',
                'Une soumission postérieure à cette borne est exposée comme tardive et exclue du numérateur.',
                'Une soumission vide, partielle ou hors parcours est exposée comme invalide et exclue du numérateur.',
                'Les événements publics avec un identifiant de navigateur sont dédupliqués par type et par session ; ils mesurent un intérêt, pas un rendez-vous commercial réalisé.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function activationMetrics(Collection $registrations, Collection $eventsByActor, Carbon $end): array
    {
        $eligible = $registrations
            ->filter(fn (ProductEvent $registration) => $registration->occurred_at->lte($end->copy()->subDays(7)))
            ->values();

        $createdAccounts = [];
        $activatedAccounts = [];
        $publicationMinutes = [];

        foreach ($eligible as $registration) {
            $actorEvents = $eventsByActor->get($registration->actor_key, collect());
            $windowEnd = $registration->occurred_at->copy()->addDays(7);
            $created = $actorEvents
                ->where('event', 'quiz_created')
                ->filter(fn (ProductEvent $event) => $event->occurred_at->betweenIncluded($registration->occurred_at, $windowEnd))
                ->sortBy('occurred_at')
                ->first();

            if ($created) {
                $createdAccounts[] = $registration->actor_key;
            }

            $paired = $actorEvents
                ->where('event', 'quiz_created')
                ->filter(fn (ProductEvent $event) => $event->occurred_at->betweenIncluded($registration->occurred_at, $windowEnd))
                ->sortBy('occurred_at')
                ->first(function (ProductEvent $createdEvent) use ($actorEvents, $windowEnd) {
                    return $actorEvents
                        ->where('event', 'quiz_published')
                        ->where('subject_key', $createdEvent->subject_key)
                        ->contains(fn (ProductEvent $publishedEvent) => $publishedEvent->occurred_at
                            ->betweenIncluded($createdEvent->occurred_at, $windowEnd));
                });

            if (!$paired) {
                continue;
            }

            $published = $actorEvents
                ->where('event', 'quiz_published')
                ->where('subject_key', $paired->subject_key)
                ->filter(fn (ProductEvent $event) => $event->occurred_at->betweenIncluded($paired->occurred_at, $windowEnd))
                ->sortBy('occurred_at')
                ->first();

            $minutes = $registration->occurred_at->diffInSeconds($published->occurred_at) / 60;
            $publicationMinutes[] = $minutes;
            $activatedAccounts[] = [
                'actor_key' => $registration->actor_key,
                'registered_at' => $registration->occurred_at,
            ];
        }

        $denominator = $eligible->count();
        $createdNumerator = count(array_unique($createdAccounts));
        $activatedNumerator = count($activatedAccounts);
        $median = $this->percentile($publicationMinutes, 0.50);

        return [
            'activated_accounts' => collect($activatedAccounts),
            'metrics' => [
                'registered_trainers_in_period' => $registrations->count(),
                'eligible_registered_trainers' => $denominator,
                'immature_registered_trainers' => $registrations->count() - $denominator,
                'first_quiz_created_within_7_days' => [
                    'numerator' => $createdNumerator,
                    'denominator' => $denominator,
                    'rate_percent' => $this->rate($createdNumerator, $denominator),
                ],
                'created_and_published_within_7_days' => [
                    'numerator' => $activatedNumerator,
                    'denominator' => $denominator,
                    'rate_percent' => $this->rate($activatedNumerator, $denominator),
                    'target_percent_strictly_above' => 50,
                    'target_met' => $denominator > 0 ? $activatedNumerator / $denominator > 0.50 : null,
                ],
                'registration_to_first_publication_minutes' => [
                    'sample_size' => count($publicationMinutes),
                    'median' => $median,
                    'p75' => $this->percentile($publicationMinutes, 0.75),
                    'p90' => $this->percentile($publicationMinutes, 0.90),
                    'target_median_strictly_below' => 10,
                    'target_met' => $median !== null ? $median < 10 : null,
                ],
            ],
        ];
    }

    private function completionMetrics(Carbon $start, Carbon $end, string $environment): array
    {
        $allAttempts = QuizAttempt::query()
            ->where('environment', $environment)
            ->where('is_internal', false)
            ->whereBetween('started_at', [$start, $end])
            ->get();

        $attempts = $allAttempts
            ->filter(fn (QuizAttempt $attempt) => $attempt->matures_at->lte($end));

        $denominator = $attempts->count();
        $completed = $attempts->filter(fn (QuizAttempt $attempt) => $attempt->is_valid_completion
            && $attempt->submitted_at
            && $attempt->submitted_at->lte($attempt->matures_at));
        $lateSubmissions = $attempts->filter(fn (QuizAttempt $attempt) => $attempt->submitted_at
            && $attempt->submitted_at->gt($attempt->matures_at));
        $invalidSubmissions = $attempts->filter(fn (QuizAttempt $attempt) => !$attempt->is_valid_completion
            && $attempt->submitted_at
            && $attempt->submitted_at->lte($attempt->matures_at));
        $numerator = $completed->count();

        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'immature_attempts' => $allAttempts->count() - $denominator,
            'abandoned_attempts' => $attempts->whereNull('submitted_at')->count(),
            'invalid_submissions' => $invalidSubmissions->count(),
            'late_submissions' => $lateSubmissions->count(),
            'rate_percent' => $this->rate($numerator, $denominator),
            'manual_submissions' => $completed->where('submission_mode', 'manual')->count(),
            'automatic_submissions' => $completed->where('submission_mode', 'automatic')->count(),
            'channels_included' => ['public_link'],
            'channels_excluded' => ['authenticated_student', 'mindset_interview'],
            'target_percent_strictly_above' => 70,
            'target_met' => $denominator > 0 ? $numerator / $denominator > 0.70 : null,
        ];
    }

    private function retentionMetrics(Collection $activatedAccounts, Collection $eventsByActor, Carbon $end): array
    {
        $eligible = $activatedAccounts
            ->filter(fn (array $account) => $account['registered_at']->lte($end->copy()->subDays(36)))
            ->values();

        $retained = $eligible->filter(function (array $account) use ($eventsByActor) {
            $windowStart = $account['registered_at']->copy()->addDays(25);
            $windowEnd = $account['registered_at']->copy()->addDays(36);

            return $eventsByActor
                ->get($account['actor_key'], collect())
                ->whereIn('event', self::USEFUL_RETENTION_EVENTS)
                ->contains(fn (ProductEvent $event) => $event->occurred_at->gte($windowStart)
                    && $event->occurred_at->lt($windowEnd));
        });

        $denominator = $eligible->count();
        $numerator = $retained->count();

        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'rate_percent' => $this->rate($numerator, $denominator),
            'window' => 'J25_inclusive_to_J36_exclusive',
            'target_percent_strictly_above' => 40,
            'target_met' => $denominator > 0 ? $numerator / $denominator > 0.40 : null,
        ];
    }

    private function configuredStart(): ?Carbon
    {
        $value = config('analytics.test_started_at');

        if (!filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value, (string) config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null;
    }

    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $position = (count($values) - 1) * $percentile;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);
        $weight = $position - $lowerIndex;
        $value = $values[$lowerIndex] + (($values[$upperIndex] - $values[$lowerIndex]) * $weight);

        return round((float) $value, 1);
    }
}
