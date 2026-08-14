<?php

namespace App\Filament\Resources\ElectricityTariffs\Pages;

use App\Filament\Resources\ElectricityTariffs\ElectricityTariffResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditElectricityTariff extends EditRecord
{
    protected static string $resource = ElectricityTariffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
