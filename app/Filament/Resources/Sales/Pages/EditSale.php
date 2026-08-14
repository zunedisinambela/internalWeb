<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\Actions\RefreshPricesAction;
use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only on the edit screen, and deliberately not on the list as a bulk
            // action. Repricing several sales at once is the shape of the thing
            // the snapshot exists to prevent — reviewing each line is the point,
            // and a bulk version could not show what it was about to change.
            //
            // No authorization of its own: reaching this page already requires
            // Update:Sale, and the action only writes into the open form, which
            // still has to pass the ordinary save.
            RefreshPricesAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
