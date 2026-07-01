<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-superadmin {email} {password} {--name=Super Admin}';

    protected $description = 'Créer ou promouvoir un super-administrateur de plateforme (rôle superadmin).';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = $this->argument('password');
        $name = $this->option('name');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'role' => 'superadmin',
                'is_blocked' => false,
                'password' => Hash::make($password),
            ]);
            $this->info("Utilisateur existant promu super-administrateur : {$email}");
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'superadmin',
            ]);
            $this->info("Super-administrateur créé : {$email}");
        }

        return self::SUCCESS;
    }
}
