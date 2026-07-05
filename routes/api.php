<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicQuizController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\Admin\SchoolClassController;
use App\Http\Controllers\Api\Admin\QuizController;
use App\Http\Controllers\Api\Admin\QuizImportController;
use App\Http\Controllers\Api\Admin\QuizConverterController;
use App\Http\Controllers\Api\Admin\ProgressiveQuizController;
use App\Http\Controllers\Api\Admin\ResultController;
use App\Http\Controllers\Api\Student\StudentQuizController;
use App\Http\Controllers\Api\SuperAdmin\StatsController;
use App\Http\Controllers\Api\SuperAdmin\RevenueController;
use App\Http\Controllers\Api\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSubscribed;
use Illuminate\Support\Facades\Route;

// Routes publiques pour accès au QCM via lien (sans authentification)
Route::prefix('public/quiz')->group(function () {
    Route::get('/{token}', [PublicQuizController::class, 'show']);
    Route::post('/{token}/start', [PublicQuizController::class, 'start']);
    Route::post('/{token}/submit', [PublicQuizController::class, 'submit']);
});

// Permet à un participant de retrouver ses notes via son identité
Route::post('public/my-results', [PublicQuizController::class, 'myResults']);

// Notification IPN PayTech (public)
Route::post('payments/paytech/ipn', [SubscriptionController::class, 'ipn']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register-admin', [AuthController::class, 'registerAdmin']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/check-email', [AuthController::class, 'checkEmail']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'updatePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', EnsureNotBlocked::class, EnsureRole::class . ':admin'])
    ->group(function () {
        // Abonnement (accessible même sans abonnement actif)
        Route::get('subscription', [SubscriptionController::class, 'status']);
        Route::post('subscription/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('subscription/verify', [SubscriptionController::class, 'verify']);

        // Fonctionnalités nécessitant un abonnement actif
        Route::middleware(EnsureSubscribed::class)->group(function () {
            Route::apiResource('classes', SchoolClassController::class)->parameters([
                'classes' => 'class',
            ]);

            Route::apiResource('quizzes', QuizController::class);
            Route::post('quizzes/{quiz}/notify', [QuizController::class, 'notify']);
            Route::post('quizzes/{quiz}/archive', [QuizController::class, 'archive']);
            Route::post('quizzes/{quiz}/unarchive', [QuizController::class, 'unarchive']);
            Route::post('quizzes/import', [QuizImportController::class, 'store']);
            Route::post('quizzes/convert', [QuizConverterController::class, 'convert']);

            // QCM progressifs (diagnostic par stades)
            Route::post('progressive-quizzes', [ProgressiveQuizController::class, 'store']);
            Route::put('progressive-quizzes/{quiz}', [ProgressiveQuizController::class, 'update']);

            Route::get('results', [ResultController::class, 'index']);
            Route::get('results/{submission}', [ResultController::class, 'show']);
        });
    });

Route::prefix('student')
    ->middleware(['auth:sanctum', EnsureNotBlocked::class, EnsureRole::class . ':student'])
    ->group(function () {
        Route::get('quizzes', [StudentQuizController::class, 'index']);
        Route::get('results', [StudentQuizController::class, 'results']);
        Route::get('quizzes/{quiz}', [StudentQuizController::class, 'show']);
        Route::get('quizzes/{quiz}/correction', [StudentQuizController::class, 'correction']);
        Route::post('quizzes/{quiz}/submit', [StudentQuizController::class, 'submit']);
    });

// Espace super-administrateur de plateforme (accès global)
Route::prefix('superadmin')
    ->middleware(['auth:sanctum', EnsureRole::class . ':superadmin'])
    ->group(function () {
        Route::get('stats', [StatsController::class, 'index']);
        Route::get('revenue', [RevenueController::class, 'index']);
        Route::get('users', [SuperAdminUserController::class, 'index']);
        Route::post('users/{user}/block', [SuperAdminUserController::class, 'block']);
        Route::post('users/{user}/unblock', [SuperAdminUserController::class, 'unblock']);
    });
