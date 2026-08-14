<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Stamps the author server-side rather than exposing a "recorded by" select.
     *
     * The model's creating() hook does the same for rows made outside a form,
     * but setting it here as well keeps the value out of the form state, where
     * a crafted request could otherwise attribute an entry to someone else.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
