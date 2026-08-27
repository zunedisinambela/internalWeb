<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\Actions\ExportTransactionsAction;
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
            // One button holding both formats — the grouping, the label and the
            // icon live on ExportRecordsAction::group() so that the four
            // screens carrying this cannot end up with four different buttons.
            // Left of the create button on purpose, since it reads what the
            // filters currently show.
            ExportTransactionsAction::group(),

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
