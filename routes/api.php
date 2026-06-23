<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicQuizController;
use App\Http\Controllers\Api\Admin\SchoolClassController;
use App\Http\Controllers\Api\Admin\QuizController;
use App\Http\Controllers\Api\Admin\QuizImportController;
use App\Http\Controllers\Api\Admin\QuizConverterController;
use App\Http\Controllers\Api\Admin\ProgressiveQuizController;
use App\Http\Controllers\Api\Admin\ResultController;
use App\Http\Controllers\Api\Student\StudentQuizController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

Route::get('/classes', [SchoolClassController::class, 'publicIndex']);

// Routes publiques pour accès au QCM via lien (sans authentification)
Route::prefix('public/quiz')->group(function () {
    Route::get('/{token}', [PublicQuizController::class, 'show']);
    Route::post('/{token}/start', [PublicQuizController::class, 'start']);
    Route::post('/{token}/submit', [PublicQuizController::class, 'submit']);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/check-email', [AuthController::class, 'checkEmail']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', EnsureRole::class . ':admin'])
    ->group(function () {
        Route::apiResource('classes', SchoolClassController::class)->parameters([
            'classes' => 'class',
        ]);

        Route::apiResource('quizzes', QuizController::class);
        Route::post('quizzes/import', [QuizImportController::class, 'store']);
        Route::post('quizzes/convert', [QuizConverterController::class, 'convert']);

        // QCM progressifs (diagnostic par stades)
        Route::post('progressive-quizzes', [ProgressiveQuizController::class, 'store']);
        Route::put('progressive-quizzes/{quiz}', [ProgressiveQuizController::class, 'update']);

        Route::get('results', [ResultController::class, 'index']);
        Route::get('results/{submission}', [ResultController::class, 'show']);
    });

Route::prefix('student')
    ->middleware(['auth:sanctum', EnsureRole::class . ':student'])
    ->group(function () {
        Route::get('quizzes', [StudentQuizController::class, 'index']);
        Route::get('quizzes/{quiz}', [StudentQuizController::class, 'show']);
        Route::post('quizzes/{quiz}/submit', [StudentQuizController::class, 'submit']);
    });
