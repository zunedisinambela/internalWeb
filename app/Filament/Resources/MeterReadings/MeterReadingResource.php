<?php

namespace App\Filament\Resources\MeterReadings;

use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\MeterReadings\Pages\ViewMeterReading;
use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;
use App\Filament\Resources\MeterReadings\Schemas\MeterReadingInfolist;
use App\Filament\Resources\MeterReadings\Tables\MeterReadingsTable;
use App\Models\MeterReading;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MeterReadingResource extends Resource
{
    protected static ?string $model = MeterReading::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|UnitEnum|null $navigationGroup = 'Kost';

    protected static ?string $navigationLabel = 'Meteran Listrik';

    // Indonesian has no plural inflection, so both labels are the same word.
    protected static ?string $modelLabel = 'pencatatan meteran';

    protected static ?string $pluralModelLabel = 'pencatatan meteran';

    // The only screen in the group. Rooms and tariffs used to sit beneath it;
    // both were folded into this one form, so the sort is now a placeholder for
    // wherever a second Kost screen would go.
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return MeterReadingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MeterReadingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MeterReadingsTable::configure($table);
    }

    /**
     * Both relations are rendered on every row — the author in a toggleable
     * column, the photographs in a stacked image column — so they are loaded
     * once per page instead of once per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'media']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeterReadings::route('/'),
            'create' => CreateMeterReading::route('/create'),
            'view' => ViewMeterReading::route('/{record}'),
            'edit' => EditMeterReading::route('/{record}/edit'),
        ];
    }

    /**
     * Who may download the meter log.
     *
     * The same permission that opens the list, for the reason the cash book
     * gives: the export shows no column the table does not.
     *
     * The PDF prints the dial photographs, which is the point of it — a
     * disputed bill is settled by comparing a figure against the photograph
     * taken when it was read. It prints the `thumb` conversion, never the
     * original, and that is not cosmetic: a meter is bolted to a building, so
     * the EXIF the phone wrote is the address of a property with tenants in it.
     * The conversion is re-encoded and loses it. See App\Support\PdfImage.
     */
    public static function canExport(): bool
    {
        return static::canViewAny();
    }
}
