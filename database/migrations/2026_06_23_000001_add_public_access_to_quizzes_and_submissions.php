<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\Quiz;

return new class extends Migration
{
    public function up(): void
    {
        // Ajouter le token d'accès public aux QCM
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique()->after('is_published');
        });

        // Générer un token pour les quiz existants
        Quiz::whereNull('access_token')->each(function ($quiz) {
            $quiz->update(['access_token' => Str::random(32)]);
        });

        // Modifier les submissions : rendre user_id nullable et ajouter nom/prenom/referentiel
        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('participant_nom')->nullable()->after('user_id');
            $table->string('participant_prenom')->nullable()->after('participant_nom');
            $table->string('participant_referentiel')->nullable()->after('participant_prenom');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['participant_nom', 'participant_prenom', 'participant_referentiel']);
        });
    }
};
