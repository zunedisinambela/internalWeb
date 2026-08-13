<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuthenticationMonitoring;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AuthenticationMonitoringPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AuthenticationMonitoring');
    }

    public function view(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('View:AuthenticationMonitoring');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AuthenticationMonitoring');
    }

    public function update(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('Update:AuthenticationMonitoring');
    }

    public function delete(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('Delete:AuthenticationMonitoring');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AuthenticationMonitoring');
    }

    public function restore(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('Restore:AuthenticationMonitoring');
    }

    public function forceDelete(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('ForceDelete:AuthenticationMonitoring');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AuthenticationMonitoring');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AuthenticationMonitoring');
    }

    public function replicate(AuthUser $authUser, AuthenticationMonitoring $authenticationMonitoring): bool
    {
        return $authUser->can('Replicate:AuthenticationMonitoring');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AuthenticationMonitoring');
    }
}
