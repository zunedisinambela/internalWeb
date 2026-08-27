<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FreeItemRedemption;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Gated on the *customer's* permissions, not on a set of its own.
 *
 * Every other policy here maps one-to-one onto permissions Shield generated
 * from a Filament resource. This model has no resource: a redemption is only
 * ever read or written from the customer's own screen, so Shield never
 * generates `ViewAny:FreeItemRedemption` and a policy naming those permissions
 * would refuse everybody, including a super admin — the permission simply would
 * not exist to be granted.
 *
 * Mapping onto the customer's permissions is also the honest reading: a
 * handover is a fact about a customer, recorded where their bonus is shown, and
 * anybody trusted to edit a customer is trusted to record one. Reading is
 * `View:Customer`; every write is `Update:Customer`, which is what the relation
 * manager's create, edit and delete actions consult.
 *
 * If redemptions ever get a screen of their own, this stops being right: give
 * the model a Filament resource, run `php artisan shield:generate`, rewrite the
 * methods below against the generated permissions and regenerate ShieldSeeder.
 */
class FreeItemRedemptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Customer');
    }

    public function view(AuthUser $authUser, FreeItemRedemption $freeItemRedemption): bool
    {
        return $authUser->can('View:Customer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Customer');
    }

    public function update(AuthUser $authUser, FreeItemRedemption $freeItemRedemption): bool
    {
        return $authUser->can('Update:Customer');
    }

    public function delete(AuthUser $authUser, FreeItemRedemption $freeItemRedemption): bool
    {
        return $authUser->can('Update:Customer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Customer');
    }
}
