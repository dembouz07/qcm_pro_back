<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('school_class_id')->nullable()->change();
            $table->boolean('require_stage_pass')->default(true)->after('stage_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('require_stage_pass');
            $table->foreignId('school_class_id')->nullable(false)->change();
        });
    }
};
