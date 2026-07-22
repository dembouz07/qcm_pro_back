<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // 'standard' = QCM classique | 'progressive' = diagnostic par stades
            $table->string('type', 20)->default('standard')->after('description');
            // Nombre de "Oui" requis pour valider un stade et passer au suivant
            $table->unsignedTinyInteger('stage_threshold')->default(5)->after('type');
        });

        Schema::table('questions', function (Blueprint $table) {
            // Numéro du stade (null pour les QCM standards)
            $table->unsignedTinyInteger('stage')->nullable()->after('order_index');
        });

        Schema::table('submissions', function (Blueprint $table) {
            // Stade atteint à l'issue du diagnostic progressif
            $table->unsignedTinyInteger('stade_atteint')->nullable()->after('note_sur_20');
            // Détail des scores par stade : { "1": 6, "2": 5, "3": 3 }
            $table->json('stage_scores')->nullable()->after('stade_atteint');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['type', 'stage_threshold']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('stage');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['stade_atteint', 'stage_scores']);
        });
    }
};
