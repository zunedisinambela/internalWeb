<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Customer;
use App\Models\Product;
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
                // Hidden until there is something to sell and somebody to sell
                // it to. Both selects are required and neither has a free-text
                // fallback, so the form would otherwise open onto empty lists and
                // refuse to save with messages naming fields rather than the
                // missing catalogue. Same rule as ListMeterReadings waiting for a
                // room.
                ->visible(fn (): bool => Customer::query()->exists() && Product::query()->exists()),
        ];
    }
}
