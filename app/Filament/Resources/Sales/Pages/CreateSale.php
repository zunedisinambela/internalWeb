<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    /**
     * Stamps the author server-side rather than exposing a "recorded by" select,
     * the same way CreateTransaction and CreateMeterReading do. The model's
     * creating() hook covers rows made outside a form; setting it here as well
     * keeps the value out of the form state, where a crafted request could
     * attribute a sale to someone else.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
