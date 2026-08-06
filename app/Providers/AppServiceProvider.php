<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend . '/forgot-password?token=' . urlencode($token)
                . '&email=' . urlencode((string) $notifiable->getEmailForPasswordReset());
        });
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('public-quiz-show', fn (Request $request) => Limit::perMinute(300)
            ->by($this->rateKey('public-quiz-show', $request, (string) $request->route('token'))));

        RateLimiter::for('public-quiz-start', fn (Request $request) => [
            Limit::perMinute(120)
                ->by($this->rateKey('public-quiz-start', $request, (string) $request->route('token'))),
            Limit::perHour(500)
                ->by('public-quiz-start-resource:' . hash('sha256', (string) $request->route('token'))),
        ]);

        RateLimiter::for('public-quiz-submit', fn (Request $request) => [
            Limit::perMinute(180)
                ->by($this->rateKey('public-quiz-submit', $request, (string) $request->route('token'))),
            Limit::perMinute(12)
                ->by('public-quiz-submit-attempt:' . hash('sha256', (string) $request->input('attempt_id', 'missing'))),
        ]);

        RateLimiter::for('public-result', function (Request $request): array {
            $secretFingerprint = hash('sha256', (string) $request->input('access_token', 'missing'));

            return [
                Limit::perMinute(60)->by($this->rateKey('public-result-ip', $request)),
                Limit::perMinute(10)->by('public-result-secret:' . $secretFingerprint),
            ];
        });

        RateLimiter::for('public-event', fn (Request $request) => [
            Limit::perMinute(20)->by($this->rateKey('public-event-minute', $request)),
            Limit::perHour(100)->by($this->rateKey('public-event-hour', $request)),
        ]);

        RateLimiter::for('public-survey-show', fn (Request $request) => Limit::perMinute(300)
            ->by($this->rateKey('public-survey-show', $request, (string) $request->route('token'))));

        RateLimiter::for('public-survey-submit', fn (Request $request) => [
            Limit::perMinute(180)
                ->by($this->rateKey('public-survey-submit', $request, (string) $request->route('token'))),
            Limit::perHour(500)
                ->by('public-survey-submit-resource:' . hash('sha256', (string) $request->route('token'))),
        ]);
    }

    private function rateKey(string $scope, Request $request, string $resource = ''): string
    {
        return $scope . ':' . hash('sha256', $request->ip() . '|' . $resource);
    }
}
