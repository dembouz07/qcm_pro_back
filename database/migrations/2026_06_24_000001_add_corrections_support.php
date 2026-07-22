<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('show_corrections')->default(false)->after('is_published');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->text('explanation')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('show_corrections');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });
    }
};
