<?php

namespace App\Filament\Resources\MeterReadings\Pages;

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
            // No rate-refresh action any more, and no rate either. It existed to
            // recopy a price from the tariff table when one had been entered
            // wrong; the amount is typed off the bill now, so the field itself
            // is the correction.
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
