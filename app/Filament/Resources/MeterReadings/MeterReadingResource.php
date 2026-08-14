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

    // First in the group: this is the screen that is worked in every month,
    // while rooms and tariffs are set up once and then consulted.
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
     * Both relations are rendered on every row — the room in a column with its
     * occupant underneath, the photographs in a stacked image column — so they
     * are loaded once per page instead of once per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['room', 'user', 'media']);
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
}
