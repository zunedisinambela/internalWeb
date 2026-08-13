<?php

namespace App\Filament\Resources\Authentications\Pages;

use App\Filament\Resources\Authentications\AuthenticationResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthentications extends ListRecords
{
    protected static string $resource = AuthenticationResource::class;

    /**
     * No header actions: rows come from the Login and Logout events only.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
