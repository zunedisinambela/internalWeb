<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            // Hides itself unless the record has two-factor on and is someone
            // other than the viewer — see UserResource::canResetTwoFactor().
            ResetTwoFactorAction::make(),
        ];
    }
}
