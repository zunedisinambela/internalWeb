<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Customer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Catat penjualan')
                // Hidden until there is somebody to sell to. The customer select
                // is required and has no free-text fallback, so the form would
                // otherwise open onto an empty list and refuse to save with a
                // message naming a field rather than the missing customer. Same
                // rule as ListMeterReadings waiting for a room.
                //
                // Only one prerequisite now that there is no product catalogue —
                // the prices are typed onto the sale itself.
                ->visible(fn (): bool => Customer::query()->exists()),
        ];
    }
}
