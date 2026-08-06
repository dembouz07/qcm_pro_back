<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120)->unique();
            $table->string('event', 80);
            $table->string('actor_key', 64)->nullable();
            $table->string('account_role', 30)->nullable();
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_key', 64)->nullable();
            $table->string('environment', 30);
            $table->boolean('is_internal')->default(false);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['event', 'occurred_at']);
            $table->index(['actor_key', 'occurred_at']);
            $table->index(['environment', 'is_internal', 'occurred_at'], 'product_events_scope_index');
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('quiz_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('result_access_token_hash', 64)->nullable()->unique();
            $table->timestamp('result_access_expires_at')->nullable();
            $table->string('channel', 30)->default('public_link');
            $table->string('environment', 30);
            $table->boolean('is_internal')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('matures_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('submission_mode', 20)->nullable();
            $table->boolean('is_valid_completion')->default(false);
            $table->string('invalid_reason', 80)->nullable();
            $table->timestamps();

            $table->index(['matures_at', 'submitted_at']);
            $table->index(['quiz_id', 'started_at']);
            $table->index(['environment', 'is_internal', 'started_at'], 'quiz_attempts_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('product_events');
    }
};
