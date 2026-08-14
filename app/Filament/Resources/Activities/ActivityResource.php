<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\Schemas\ActivityInfolist;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Browser for the spatie/laravel-activitylog table.
 *
 * This table is the backstop for the other two monitoring screens: deleting a
 * visit or a sign-in writes an entry here, which is what stops those deletions
 * from being silent. Allowing entries here to be deleted therefore closes a
 * loop the rest of the design depends on — someone could clear their visits,
 * then clear the record of having cleared them.
 *
 * What keeps that from being untraceable is one tier up: every deletion here is
 * written to the application log by AppServiceProvider::registerActivityDeletionLogging(),
 * readable at /log-viewer. Log files are not writable from the panel, so that
 * is where the chain ends.
 *
 * Create and edit stay refused — entries are written by the application, and an
 * editable audit entry is worse than a deleted one because it still looks true.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Log Aktivitas';

    protected static ?string $modelLabel = 'aktivitas';

    protected static ?string $pluralModelLabel = 'aktivitas';

    protected static ?int $navigationSort = 90;

    public static function infolist(Schema $schema): Schema
    {
        return ActivityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    /**
     * Eager load both morphs so the table does not fire a query per row for the
     * causer and subject columns.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer', 'subject']);
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
    // Delete:Activity and DeleteAny:Activity are handed out at
    // /shield/roles. Of the three monitoring screens this is the one
    // worth granting most narrowly.

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }
}
