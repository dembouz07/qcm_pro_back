<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->change();
            $table->timestamp('closed_at')->nullable()->after('ends_at');
        });

        DB::table('quizzes')
            ->where('type', 'progressive')
            ->update([
                'starts_at' => null,
                'ends_at' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('quizzes')
            ->whereNull('starts_at')
            ->update(['starts_at' => now()]);

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('closed_at');
            $table->dateTime('starts_at')->nullable(false)->change();
        });
    }
};
