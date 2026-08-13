<?php

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes role grants and revocations to the activity log.
 *
 * Since panel access is granted by holding a role, this is the privilege
 * escalation trail. LogsActivity cannot cover it: it watches model attributes,
 * and roles live in a pivot table. Requires permission.events_enabled = true.
 */
class LogRoleChange
{
    public function attached(RoleAttachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, 'granted');
    }

    public function detached(RoleDetachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, 'revoked');
    }

    protected function log(Model $model, mixed $rolesOrIds, string $action): void
    {
        $roles = $this->resolveNames($rolesOrIds);

        if ($roles === []) {
            return;
        }

        activity('user')
            ->performedOn($model)
            ->withProperties(['roles' => $roles])
            ->event($action === 'granted' ? 'role_granted' : 'role_revoked')
            ->log(sprintf('Roles %s: %s', $action, implode(', ', $roles)));
    }

    /**
     * The event may carry ids, Role instances, or a mix of both, in an array or
     * a Collection. Anything that is not already a Role is looked up by id.
     *
     * @return array<int, string>
     */
    protected function resolveNames(mixed $rolesOrIds): array
    {
        $items = match (true) {
            $rolesOrIds instanceof Collection => $rolesOrIds->all(),
            is_array($rolesOrIds) => $rolesOrIds,
            default => [$rolesOrIds],
        };

        $names = [];
        $ids = [];

        foreach ($items as $item) {
            if ($item instanceof RoleContract) {
                $names[] = $item->name;

                continue;
            }

            $ids[] = $item;
        }

        if ($ids !== []) {
            $roleClass = app(PermissionRegistrar::class)->getRoleClass();

            $names = array_merge(
                $names,
                $roleClass::query()->whereIn('id', $ids)->pluck('name')->all(),
            );
        }

        return array_values(array_unique(array_filter($names)));
    }
}
