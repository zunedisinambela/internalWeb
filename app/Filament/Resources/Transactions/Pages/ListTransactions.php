<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Transactions\Widgets\TransactionOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat transaksi'),
        ];
    }

    /**
     * The totals sit above the table, not on the dashboard. See the note on the
     * widget itself for why that placement is deliberate rather than cosmetic.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            TransactionOverview::class,
        ];
    }
}
