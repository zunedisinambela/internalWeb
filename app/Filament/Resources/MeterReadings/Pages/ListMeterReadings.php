<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Models\Room;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMeterReadings extends ListRecords
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Catat meteran')
                // Hidden until at least one room exists. room_id is required and
                // has no free-text fallback, so the form would open onto a select
                // with nothing in it and refuse to save with a message that names
                // the field rather than the missing room.
                ->visible(fn (): bool => Room::query()->exists()),
        ];
    }
}
