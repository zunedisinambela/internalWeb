<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ElectricityTariff;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ElectricityTariffPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ElectricityTariff');
    }

    public function view(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('View:ElectricityTariff');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ElectricityTariff');
    }

    public function update(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('Update:ElectricityTariff');
    }

    public function delete(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('Delete:ElectricityTariff');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ElectricityTariff');
    }

    public function restore(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('Restore:ElectricityTariff');
    }

    public function forceDelete(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('ForceDelete:ElectricityTariff');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ElectricityTariff');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ElectricityTariff');
    }

    public function replicate(AuthUser $authUser, ElectricityTariff $electricityTariff): bool
    {
        return $authUser->can('Replicate:ElectricityTariff');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ElectricityTariff');
    }
}
