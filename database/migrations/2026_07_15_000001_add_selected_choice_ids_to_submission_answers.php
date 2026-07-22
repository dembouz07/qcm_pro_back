<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_answers', function (Blueprint $table) {
            // Liste des choix sélectionnés (pour les questions à réponses multiples).
            $table->json('selected_choice_ids')->nullable()->after('choice_id');
        });
    }

    public function down(): void
    {
        Schema::table('submission_answers', function (Blueprint $table) {
            $table->dropColumn('selected_choice_ids');
        });
    }
};
