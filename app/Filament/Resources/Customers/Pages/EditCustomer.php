<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Concerns\ReturnsToListAfterSaving;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    use ReturnsToListAfterSaving;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
