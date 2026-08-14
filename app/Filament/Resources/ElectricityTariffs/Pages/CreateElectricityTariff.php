<?php

namespace App\Filament\Resources\ElectricityTariffs\Pages;

use App\Filament\Resources\ElectricityTariffs\ElectricityTariffResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateElectricityTariff extends CreateRecord
{
    protected static string $resource = ElectricityTariffResource::class;

    /**
     * Stamps the author server-side rather than exposing a select, the same way
     * CreateTransaction does. Who raised the rate is the question this table
     * exists to answer, so it must not be settable from the request.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
