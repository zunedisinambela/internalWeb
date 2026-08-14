<?php

namespace App\Filament\Resources\ElectricityTariffs\Pages;

use App\Filament\Resources\ElectricityTariffs\ElectricityTariffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListElectricityTariffs extends ListRecords
{
    protected static string $resource = ElectricityTariffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // "Tetapkan tarif baru", not "Tambah": the screen is a history, and
            // the button is how a rate is changed. Naming it after adding a row
            // would invite someone to edit the existing one instead, which looks
            // like it works and quietly rewrites nothing — past readings keep
            // their own copy of the rate.
            CreateAction::make()->label('Tetapkan tarif baru'),
        ];
    }
}
