<?php

namespace App\Filament\Resources\Authentications;

use App\Filament\Resources\Authentications\Pages\ListAuthentications;
use App\Filament\Resources\Authentications\Tables\AuthenticationsTable;
use App\Models\AuthenticationMonitoring;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Browser for authentications_monitoring.
 *
 * Sign-in history is the other half of the privilege-escalation trail:
 * activity_log says who changed a role, this says from where and when they
 * were signed in.
 *
 * Rows are deletable, like visits — but unlike visits they are not routine
 * housekeeping, so deleting one is worth noticing. Two things keep that
 * honest: who may do it comes from AuthenticationMonitoringPolicy rather than
 * a hardcoded rule, and every removal is written to the activity log by
 * AuthenticationMonitoring::booted(), which this screen cannot reach.
 *
 * Nothing creates or edits a sign-in by hand, so those two stay refused.
 */
class AuthenticationResource extends Resource
{
    protected static ?string $model = AuthenticationMonitoring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $navigationLabel = 'Riwayat Masuk';

    protected static ?string $slug = 'authentications';

    protected static ?string $modelLabel = 'riwayat masuk';

    // Indonesian does not inflect for plural — same word both ways.
    protected static ?string $pluralModelLabel = 'riwayat masuk';

    protected static ?int $navigationSort = 92;

    public static function table(Table $table): Table
    {
        return AuthenticationsTable::configure($table);
    }

    /**
     * user_id is nullOnDelete(), so rows survive the account they belong to
     * and the relation legitimately resolves to null on those.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    // canDelete() and canDeleteAny() are deliberately not overridden, so
    // Delete:AuthenticationMonitoring and DeleteAny:AuthenticationMonitoring
    // are handed out at /admin/shield/roles like every other permission.
    // Worth keeping narrower than the visits equivalent: this is the record of
    // who had access and when.

    public static function getPages(): array
    {
        return [
            'index' => ListAuthentications::route('/'),
        ];
    }
}
