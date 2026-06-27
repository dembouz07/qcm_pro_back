<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\SchoolClass;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });

        // Rattacher les classes existantes au premier admin (pour ne rien perdre)
        $firstAdmin = User::where('role', 'admin')->orderBy('id')->first();
        if ($firstAdmin) {
            SchoolClass::whereNull('owner_id')->update(['owner_id' => $firstAdmin->id]);
        }

        // Générer un code pour les classes qui n'en ont pas (sert à l'inscription des élèves)
        SchoolClass::query()
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))
            ->get()
            ->each(function (SchoolClass $class) {
                $class->update(['code' => $this->uniqueCode()]);
            });
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (SchoolClass::where('code', $code)->exists());

        return $code;
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
