<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mindset_assessments', function (Blueprint $table) {
            $table->string('methodology_version', 20)->default('1.0')->after('type');
            $table->string('methodology_hash', 64)->nullable()->after('methodology_version');
            $table->json('methodology_snapshot')->nullable()->after('methodology_hash');
        });
    }

    public function down(): void
    {
        Schema::table('mindset_assessments', function (Blueprint $table) {
            $table->dropColumn(['methodology_version', 'methodology_hash', 'methodology_snapshot']);
        });
    }
};
