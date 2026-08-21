<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the default Filament admin account.
     */
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                // The second identifier the login page accepts. Same account,
                // same weak password — see the note in CLAUDE.md.
                'username' => 'admin',
                'password' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        // Holding a role is what grants panel access, so this is the line that
        // makes the account usable. ShieldSeeder must have run first.
        $user->syncRoles([Utils::getSuperAdminName()]);
    }
}
