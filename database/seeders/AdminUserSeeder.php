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
                'name' => 'ZUNEDI',
                // The second identifier the login page accepts, and the one
                // actually typed — the address is only the lookup key here, so
                // it stays admin@admin.com and the account is signed into as
                // 'zunedi'. Lowercase because usernames are stored folded; see
                // Sign-in identifiers in docs/access-control.md.
                'username' => 'zunedi',
                // Assigned raw on purpose. User::casts() marks this 'hashed',
                // so Eloquent hashes on assign and a Hash::make() here would
                // store a hash of a hash and fail every login silently.
                //
                // It is in the repository, which is what makes it a local-only
                // credential regardless of how it reads. Rotate it from
                // /profile on anything that is not a dev machine — see the note
                // in CLAUDE.md.
                'password' => 'Sinambela#123',
                'email_verified_at' => now(),
            ],
        );

        // Holding a role is what grants panel access, so this is the line that
        // makes the account usable. ShieldSeeder must have run first.
        $user->syncRoles([Utils::getSuperAdminName()]);
    }
}
