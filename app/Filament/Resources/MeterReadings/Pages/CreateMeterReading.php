<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateMeterReading extends CreateRecord
{
    protected static string $resource = MeterReadingResource::class;

    /**
     * Stamps the author server-side rather than exposing a "recorded by" select,
     * the same way CreateTransaction does. The model's creating() hook covers
     * rows made outside a form; setting it here as well keeps the value out of
     * the form state, where a crafted request could attribute a reading to
     * someone else.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
