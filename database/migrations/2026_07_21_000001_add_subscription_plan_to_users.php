<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_plan', 20)->default('free')->after('subscription_status');
        });

        // Les abonnés existants conservent toutes les fonctionnalités qu'ils avaient.
        DB::table('users')
            ->where('role', 'admin')
            ->where('subscription_status', 'active')
            ->update(['subscription_plan' => 'premium']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subscription_plan');
        });
    }
};
