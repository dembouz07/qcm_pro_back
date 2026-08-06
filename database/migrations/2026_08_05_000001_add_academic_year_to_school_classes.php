<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('academic_year', 9)->nullable()->after('name');
        });

        $today = now();
        $startYear = $today->month >= 8 ? $today->year : $today->year - 1;

        DB::table('school_classes')
            ->whereNull('academic_year')
            ->update([
                'academic_year' => sprintf('%d-%d', $startYear, $startYear + 1),
            ]);

        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('academic_year', 9)->nullable(false)->change();
            $table->dropUnique(['name']);
            $table->unique(
                ['owner_id', 'name', 'academic_year'],
                'school_classes_owner_name_academic_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique('school_classes_owner_name_academic_year_unique');
            $table->dropColumn('academic_year');
            $table->unique('name');
        });
    }
};
