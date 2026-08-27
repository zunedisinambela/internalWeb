<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMeterReadings extends ListRecords
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No longer gated on anything. It used to be hidden until a room
            // existed, because room_id was required and had no free-text
            // fallback; with the room gone, a first reading needs nothing set up
            // beforehand.
            CreateAction::make()
                ->label('Catat meteran'),
        ];
    }
}
