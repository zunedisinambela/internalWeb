<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\Actions\ExportTransactionsAction;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Transactions\Widgets\TransactionOverview;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Grouped rather than two buttons: it is one act — take a copy of
            // the book — and the file format is a detail of it. Left of the
            // create button on purpose, since it reads what the filters
            // currently show.
            ActionGroup::make([
                ExportTransactionsAction::excel(),
                ExportTransactionsAction::pdf(),
            ])
                ->label('Unduh')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),

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
