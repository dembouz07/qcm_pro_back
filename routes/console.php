<?php

use Illuminate\Foundation\Inspiring;
use App\Models\ProductEvent;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->hourly();
Schedule::call(function (): void {
    ProductEvent::query()
        ->where('occurred_at', '<', now()->subMonths(12))
        ->delete();
})->dailyAt('03:10')->name('analytics:prune-product-events');
Schedule::call(function (): void {
    QuizAttempt::query()
        ->where('created_at', '<', now()->subMonths(12))
        ->delete();
})->dailyAt('03:20')->name('analytics:prune-quiz-attempts');
Schedule::call(function (): void {
    QuizAttempt::query()
        ->whereNotNull('result_access_token_hash')
        ->where('result_access_expires_at', '<', now())
        ->update(['result_access_token_hash' => null]);
})->hourly()->name('public-results:expire-access-tokens');
