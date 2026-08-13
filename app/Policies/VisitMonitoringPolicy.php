<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VisitMonitoring;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VisitMonitoringPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VisitMonitoring');
    }

    public function view(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('View:VisitMonitoring');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VisitMonitoring');
    }

    public function update(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('Update:VisitMonitoring');
    }

    public function delete(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('Delete:VisitMonitoring');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VisitMonitoring');
    }

    public function restore(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('Restore:VisitMonitoring');
    }

    public function forceDelete(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('ForceDelete:VisitMonitoring');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VisitMonitoring');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VisitMonitoring');
    }

    public function replicate(AuthUser $authUser, VisitMonitoring $visitMonitoring): bool
    {
        return $authUser->can('Replicate:VisitMonitoring');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VisitMonitoring');
    }
}
