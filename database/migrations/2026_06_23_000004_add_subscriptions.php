<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_status', 20)->default('inactive')->after('role');
            $table->dateTime('subscribed_until')->nullable()->after('subscription_status');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30)->default('paydunya');
            $table->string('token')->nullable()->index();   // token de la facture PayDunya
            $table->unsignedInteger('amount')->default(1000);
            $table->string('currency', 10)->default('XOF');
            $table->string('status', 20)->default('pending'); // pending | completed | failed
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'subscribed_until']);
        });
        Schema::dropIfExists('payments');
    }
};
