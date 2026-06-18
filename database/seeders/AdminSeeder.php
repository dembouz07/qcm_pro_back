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
        $class = SchoolClass::firstOrCreate(
            ['name' => 'Terminale A'],
            ['code' => 'TA']
        );

        SchoolClass::firstOrCreate(
            ['name' => 'Première S'],
            ['code' => 'PS']
        );

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrateur',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'school_class_id' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'eleve@example.com'],
            [
                'name' => 'Élève Démo',
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_class_id' => $class->id,
            ]
        );
    }
}
