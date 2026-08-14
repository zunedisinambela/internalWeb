<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MeterReading;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MeterReadingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MeterReading');
    }

    public function view(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('View:MeterReading');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MeterReading');
    }

    public function update(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('Update:MeterReading');
    }

    public function delete(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('Delete:MeterReading');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MeterReading');
    }

    public function restore(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('Restore:MeterReading');
    }

    public function forceDelete(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('ForceDelete:MeterReading');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MeterReading');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MeterReading');
    }

    public function replicate(AuthUser $authUser, MeterReading $meterReading): bool
    {
        return $authUser->can('Replicate:MeterReading');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MeterReading');
    }
}
