<?php

namespace Tests;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user, optionally with a role. Panel and log viewer access are
     * granted by holding any role, so $role = null produces a user that both
     * gates must refuse.
     */
    protected function userWithRole(?string $role = null, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => $role ?? 'No Role',
            'email' => ($role ?? 'norole').'@admin.com',
            'password' => 'secret',
        ], $attributes));

        if ($role !== null) {
            $this->seedRoles();
            $user->assignRole($role);
        }

        return $user;
    }

    protected function superAdmin(array $attributes = []): User
    {
        return $this->userWithRole(Utils::getSuperAdminName(), $attributes);
    }

    /**
     * Roles and permissions come from ShieldSeeder so tests exercise the same
     * data a deploy produces. The permission cache is per-request and must be
     * cleared, or a role created mid-test stays invisible to Gate checks.
     */
    protected function seedRoles(): void
    {
        if (Role::query()->doesntExist()) {
            $this->seed(ShieldSeeder::class);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
