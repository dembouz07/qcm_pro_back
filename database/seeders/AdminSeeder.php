<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('AdminSeeder est réservé aux environnements local et testing.');
        }

        $demoPassword = (string) env('DEMO_SEED_PASSWORD', '');
        if (mb_strlen($demoPassword) < 12) {
            throw new \RuntimeException('Définissez DEMO_SEED_PASSWORD avec au moins 12 caractères avant de lancer AdminSeeder.');
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrateur',
                'password' => Hash::make($demoPassword),
                'role' => 'admin',
                'school_class_id' => null,
            ]
        );

        $academicYear = SchoolClass::currentAcademicYear();
        $class = SchoolClass::firstOrCreate(
            [
                'name' => 'Terminale A',
                'academic_year' => $academicYear,
                'owner_id' => $admin->id,
            ],
            [
                'code' => $this->availableCode('TA', $academicYear),
            ]
        );

        SchoolClass::firstOrCreate(
            [
                'name' => 'Première S',
                'academic_year' => $academicYear,
                'owner_id' => $admin->id,
            ],
            [
                'code' => $this->availableCode('PS', $academicYear),
            ]
        );

        User::updateOrCreate(
            ['email' => 'eleve@example.com'],
            [
                'name' => 'Élève Démo',
                'password' => Hash::make($demoPassword),
                'role' => 'student',
                'school_class_id' => $class->id,
            ]
        );
    }

    private function availableCode(string $prefix, string $academicYear): string
    {
        [$startYear, $endYear] = explode('-', $academicYear);
        $base = strtoupper($prefix . substr($startYear, -2) . substr($endYear, -2));
        $code = $base;
        $suffix = 1;

        while (SchoolClass::where('code', $code)->exists()) {
            $code = $base . $suffix;
            $suffix++;
        }

        return $code;
    }
}
