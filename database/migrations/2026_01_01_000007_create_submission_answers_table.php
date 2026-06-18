<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('choice_id')->constrained('choices')->cascadeOnDelete();
            $table->boolean('is_correct')->default(false);
            $table->decimal('points_awarded', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['submission_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answers');
    }
};
