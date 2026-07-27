<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->timestamps();
        });

        Schema::create('company_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->unsignedSmallInteger('seniority_months')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'last_name', 'first_name']);
            $table->unique(['company_id', 'email']);
        });

        Schema::create('mindset_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('assessed_at');
            $table->unsignedTinyInteger('total_score');
            $table->string('level', 100);
            $table->json('action_items')->nullable();
            $table->text('support_needs')->nullable();
            $table->date('next_review_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type', 'assessed_at']);
            $table->index(['company_employee_id', 'type', 'assessed_at']);
        });

        Schema::create('mindset_assessment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindset_assessment_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 80);
            $table->string('pillar', 80);
            $table->unsignedTinyInteger('score');
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['mindset_assessment_id', 'question_key']);
            $table->index(['pillar', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindset_assessment_responses');
        Schema::dropIfExists('mindset_assessments');
        Schema::dropIfExists('company_employees');
        Schema::dropIfExists('companies');
    }
};
