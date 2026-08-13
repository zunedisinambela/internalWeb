<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Order matters: AdminUserSeeder assigns the super_admin role, so the
        // roles and permissions have to exist first.
        $this->call([
            ShieldSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
