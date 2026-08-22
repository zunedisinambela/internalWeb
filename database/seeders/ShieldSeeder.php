<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class ShieldSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $tenants = '[]';
        $users = '[]';
        $userTenantPivot = '[]';
        $rolesWithPermissions = '[{"name":"super_admin","guard_name":"web","permissions":["Create:Activity","Create:Role","Delete:Activity","Delete:Role","DeleteAny:Activity","DeleteAny:Role","ForceDelete:Activity","ForceDelete:Role","ForceDeleteAny:Activity","ForceDeleteAny:Role","Reorder:Activity","Reorder:Role","Replicate:Activity","Replicate:Role","Restore:Activity","Restore:Role","RestoreAny:Activity","RestoreAny:Role","Update:Activity","Update:Role","View:Activity","View:Role","ViewAny:Activity","ViewAny:Role","ViewAny:User","View:User","Create:User","Update:User","Delete:User","DeleteAny:User","Restore:User","ForceDelete:User","ForceDeleteAny:User","RestoreAny:User","Replicate:User","Reorder:User","ViewAny:AuthenticationMonitoring","View:AuthenticationMonitoring","Create:AuthenticationMonitoring","Update:AuthenticationMonitoring","Delete:AuthenticationMonitoring","DeleteAny:AuthenticationMonitoring","Restore:AuthenticationMonitoring","ForceDelete:AuthenticationMonitoring","ForceDeleteAny:AuthenticationMonitoring","RestoreAny:AuthenticationMonitoring","Replicate:AuthenticationMonitoring","Reorder:AuthenticationMonitoring","ViewAny:VisitMonitoring","View:VisitMonitoring","Create:VisitMonitoring","Update:VisitMonitoring","Delete:VisitMonitoring","DeleteAny:VisitMonitoring","Restore:VisitMonitoring","ForceDelete:VisitMonitoring","ForceDeleteAny:VisitMonitoring","RestoreAny:VisitMonitoring","Replicate:VisitMonitoring","Reorder:VisitMonitoring","View:MonitoringSettings","ViewAny:Transaction","View:Transaction","Create:Transaction","Update:Transaction","Delete:Transaction","DeleteAny:Transaction","Restore:Transaction","ForceDelete:Transaction","ForceDeleteAny:Transaction","RestoreAny:Transaction","Replicate:Transaction","Reorder:Transaction","ViewAny:ElectricityTariff","View:ElectricityTariff","Create:ElectricityTariff","Update:ElectricityTariff","Delete:ElectricityTariff","DeleteAny:ElectricityTariff","Restore:ElectricityTariff","ForceDelete:ElectricityTariff","ForceDeleteAny:ElectricityTariff","RestoreAny:ElectricityTariff","Replicate:ElectricityTariff","Reorder:ElectricityTariff","ViewAny:MeterReading","View:MeterReading","Create:MeterReading","Update:MeterReading","Delete:MeterReading","DeleteAny:MeterReading","Restore:MeterReading","ForceDelete:MeterReading","ForceDeleteAny:MeterReading","RestoreAny:MeterReading","Replicate:MeterReading","Reorder:MeterReading","ViewAny:Room","View:Room","Create:Room","Update:Room","Delete:Room","DeleteAny:Room","Restore:Room","ForceDelete:Room","ForceDeleteAny:Room","RestoreAny:Room","Replicate:Room","Reorder:Room","ViewAny:Customer","View:Customer","Create:Customer","Update:Customer","Delete:Customer","DeleteAny:Customer","Restore:Customer","ForceDelete:Customer","ForceDeleteAny:Customer","RestoreAny:Customer","Replicate:Customer","Reorder:Customer","ViewAny:Sale","View:Sale","Create:Sale","Update:Sale","Delete:Sale","DeleteAny:Sale","Restore:Sale","ForceDelete:Sale","ForceDeleteAny:Sale","RestoreAny:Sale","Replicate:Sale","Reorder:Sale"]}]';
        $directPermissions = '[]';

        // 1. Seed tenants first (if present)
        if (! blank($tenants) && $tenants !== '[]') {
            static::seedTenants($tenants);
        }

        // 2. Seed roles with permissions
        static::makeRolesWithPermissions($rolesWithPermissions);

        // 3. Seed direct permissions
        static::makeDirectPermissions($directPermissions);

        // 4. Seed users with their roles/permissions (if present)
        if (! blank($users) && $users !== '[]') {
            static::seedUsers($users);
        }

        // 5. Seed user-tenant pivot (if present)
        if (! blank($userTenantPivot) && $userTenantPivot !== '[]') {
            static::seedUserTenantPivot($userTenantPivot);
        }

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function seedTenants(string $tenants): void
    {
        if (blank($tenantData = json_decode($tenants, true))) {
            return;
        }

        $tenantModel = '';
        if (blank($tenantModel)) {
            return;
        }

        foreach ($tenantData as $tenant) {
            $tenantModel::firstOrCreate(
                ['id' => $tenant['id']],
                $tenant
            );
        }
    }

    protected static function seedUsers(string $users): void
    {
        if (blank($userData = json_decode($users, true))) {
            return;
        }

        $userModel = 'App\Models\User';
        $tenancyEnabled = false;

        foreach ($userData as $data) {
            // Extract role/permission data before creating user
            $roles = $data['roles'] ?? [];
            $permissions = $data['permissions'] ?? [];
            $tenantRoles = $data['tenant_roles'] ?? [];
            $tenantPermissions = $data['tenant_permissions'] ?? [];
            unset($data['roles'], $data['permissions'], $data['tenant_roles'], $data['tenant_permissions']);

            $user = $userModel::firstOrCreate(
                ['email' => $data['email']],
                $data
            );

            // Handle tenancy mode - sync roles/permissions per tenant
            if ($tenancyEnabled && (! empty($tenantRoles) || ! empty($tenantPermissions))) {
                foreach ($tenantRoles as $tenantId => $roleNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncRoles($roleNames);
                }

                foreach ($tenantPermissions as $tenantId => $permissionNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncPermissions($permissionNames);
                }
            } else {
                // Non-tenancy mode
                if (! empty($roles)) {
                    $user->syncRoles($roles);
                }

                if (! empty($permissions)) {
                    $user->syncPermissions($permissions);
                }
            }
        }
    }

    protected static function seedUserTenantPivot(string $pivot): void
    {
        if (blank($pivotData = json_decode($pivot, true))) {
            return;
        }

        $pivotTable = '';
        if (blank($pivotTable)) {
            return;
        }

        foreach ($pivotData as $row) {
            $uniqueKeys = [];

            if (isset($row['user_id'])) {
                $uniqueKeys['user_id'] = $row['user_id'];
            }

            $tenantForeignKey = 'team_id';
            if (! blank($tenantForeignKey) && isset($row[$tenantForeignKey])) {
                $uniqueKeys[$tenantForeignKey] = $row[$tenantForeignKey];
            }

            if (! empty($uniqueKeys)) {
                DB::table($pivotTable)->updateOrInsert($uniqueKeys, $row);
            }
        }
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            return;
        }

        /** @var Model $roleModel */
        $roleModel = Utils::getRoleModel();
        /** @var Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        $tenancyEnabled = false;
        $teamForeignKey = 'team_id';

        foreach ($rolePlusPermissions as $rolePlusPermission) {
            $tenantId = $rolePlusPermission[$teamForeignKey] ?? null;

            // Set tenant context for role creation and permission sync
            if ($tenancyEnabled) {
                setPermissionsTeamId($tenantId);
            }

            $roleData = [
                'name' => $rolePlusPermission['name'],
                'guard_name' => $rolePlusPermission['guard_name'],
            ];

            // Include tenant ID in role data (can be null for global roles)
            if ($tenancyEnabled && ! blank($teamForeignKey)) {
                $roleData[$teamForeignKey] = $tenantId;
            }

            $role = $roleModel::firstOrCreate($roleData);

            if (! blank($rolePlusPermission['permissions'])) {
                $permissionModels = collect($rolePlusPermission['permissions'])
                    ->map(fn ($permission) => $permissionModel::firstOrCreate([
                        'name' => $permission,
                        'guard_name' => $rolePlusPermission['guard_name'],
                    ]))
                    ->all();

                $role->syncPermissions($permissionModels);
            }
        }
    }

    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (blank($permissions = json_decode($directPermissions, true))) {
            return;
        }

        /** @var Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        foreach ($permissions as $permission) {
            if ($permissionModel::whereName($permission['name'])->doesntExist()) {
                $permissionModel::create([
                    'name' => $permission['name'],
                    'guard_name' => $permission['guard_name'],
                ]);
            }
        }
    }
}
