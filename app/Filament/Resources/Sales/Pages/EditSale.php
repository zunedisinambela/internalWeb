<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Concerns\ReturnsToListAfterSaving;
use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    use ReturnsToListAfterSaving;

    protected static string $resource = SaleResource::class;

    /**
     * No refresh-prices action any more.
     *
     * That button existed because each sale line carried a snapshot of a
     * product's prices, and an honest mistake needed a way back to the current
     * catalogue that was not tinker. There is no catalogue and no snapshot now —
     * every figure on a sale was typed onto that row — so correcting one is
     * editing the field, which is what this screen is.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
