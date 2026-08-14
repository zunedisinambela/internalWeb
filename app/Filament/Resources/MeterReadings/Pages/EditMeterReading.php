<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\Actions\RefreshRateAction;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMeterReading extends EditRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only here, and deliberately not as a bulk action on the list.
            // Repricing several readings at once is the shape of the thing the
            // snapshot exists to prevent, and a bulk version could not show the
            // bill each row would end up with — which is the whole confirmation.
            //
            // No authorization of its own: reaching this page already requires
            // Update:MeterReading, and the action only writes into the open form,
            // which still has to pass the ordinary save.
            RefreshRateAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
