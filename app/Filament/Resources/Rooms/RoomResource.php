<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Filament\Resources\Rooms\Tables\RoomsTable;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Kost';

    protected static ?string $navigationLabel = 'Kamar';

    // Indonesian has no plural inflection, so both labels are the same word.
    protected static ?string $modelLabel = 'kamar';

    protected static ?string $pluralModelLabel = 'kamar';

    // After the meter readings: this screen is set up once and then mostly read,
    // while readings are entered every month.
    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoomInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    /**
     * Refuses to delete a room that still has readings against it.
     *
     * The database refuses this too — meter_readings.room_id is restrictOnDelete
     * — so without this check the button would hand the user a raw
     * QueryException instead of a sentence. The rule lives on the resource
     * rather than on the button because Filament consults the resource for the
     * row action *and* for every record inside a bulk delete; a check on
     * ->visible() alone would leave the bulk path throwing.
     *
     * This is the same shape as UserResource::canDelete() refusing self-deletion.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! $record->meterReadings()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'view' => ViewRoom::route('/{record}'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }
}
