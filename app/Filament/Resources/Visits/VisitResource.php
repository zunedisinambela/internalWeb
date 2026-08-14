<?php

namespace App\Filament\Resources\Visits;

use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\Tables\VisitsTable;
use App\Models\VisitMonitoring;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Browser for visits_monitoring.
 *
 * Replaces the package's own /user-monitoring/visits-monitoring dashboard,
 * which shipped with no authorization at all.
 *
 * Rows can be deleted here, unlike ActivityResource and AuthenticationResource,
 * which stay strictly read-only. Visits are high-volume housekeeping data, so
 * clearing them is routine. Deletion is not free of consequence though: who
 * may do it comes from VisitMonitoringPolicy rather than a hardcoded rule, and
 * every removal is written to the activity log by VisitMonitoring::booted() —
 * a trail that this screen cannot reach.
 *
 * Nothing creates or edits a visit by hand, so those two stay refused.
 */
class VisitResource extends Resource
{
    protected static ?string $model = VisitMonitoring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?string $navigationLabel = 'Kunjungan';

    protected static ?string $slug = 'visits';

    protected static ?string $modelLabel = 'kunjungan';

    // Indonesian does not inflect for plural — same word both ways.
    protected static ?string $pluralModelLabel = 'kunjungan';

    protected static ?int $navigationSort = 91;

    public static function table(Table $table): Table
    {
        return VisitsTable::configure($table);
    }

    /**
     * The user column renders a name and email per row, so load the relation
     * once rather than per row. Guests have a null user_id and stay null.
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

    // canDelete() and canDeleteAny() are deliberately not overridden: the
    // resource defers to VisitMonitoringPolicy, so Delete:VisitMonitoring and
    // DeleteAny:VisitMonitoring are handed out at /shield/roles like
    // every other permission.

    public static function getPages(): array
    {
        return [
            'index' => ListVisits::route('/'),
        ];
    }
}
