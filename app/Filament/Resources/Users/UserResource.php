<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Pengguna';

    // Indonesian has no plural inflection, so both labels are the same word.
    // Filament would otherwise print "Penggunas" wherever it pluralises.
    protected static ?string $modelLabel = 'pengguna';

    protected static ?string $pluralModelLabel = 'pengguna';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * Roles are shown as a column and filtered on, so load them once per page
     * instead of once per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /**
     * Refuse self-deletion. Filament consults this for the row action and for
     * every record inside the bulk action, so one rule covers both paths — a
     * check placed only on the button would leave bulk delete open.
     */
    public static function canDelete(Model $record): bool
    {
        return ! $record->is(Auth::user());
    }

    /**
     * Whether the current user may clear someone else's two-factor secret.
     *
     * Kept on the resource rather than inline on the button, like canDelete()
     * above, so the rule has one home.
     *
     * The check is on the `super_admin` role by name, not on a Shield
     * permission, and that is the one place in this panel where it is right.
     * Removing someone's second factor is the last step of taking their account
     * over — the same screen already sets passwords — so it cannot ride along
     * with `Update:User`, which a future staff role would plausibly hold.
     * Shield's `Gate::before` hook also makes a permission check useless for
     * telling super admins apart: it passes every check for them, so
     * `can('ResetTwoFactor:User')` would answer true for everyone who has any
     * route to it at all.
     *
     * Self is excluded deliberately, and not just for tidiness: this button
     * skips the code check that the profile page enforces. Someone signed in at
     * a borrowed desk could use it to strip two-factor off the account they are
     * sitting in front of. The owner turns theirs off from /profile,
     * where a valid code is required — and if they have lost their device they
     * cannot reach this page at all, so a self button would never be usable.
     */
    public static function canResetTwoFactor(Model $record): bool
    {
        $actor = Auth::user();

        return $record instanceof User
            && $record->hasTwoFactorEnabled()
            && $actor instanceof User
            && ! $record->is($actor)
            && $actor->hasRole(Utils::getSuperAdminName());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
