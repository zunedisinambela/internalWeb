<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Filament\Actions\ExportRecordsAction;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Jobs\ExportCashBook;

/**
 * The cash book, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Everything about how an export works lives on
 * App\Filament\Actions\ExportRecordsAction — it queues rather than downloads,
 * it copies the filtered set rather than the page, and the job it dispatches is
 * what writes the audit entry. Read that class before changing anything here.
 */
class ExportTransactionsAction extends ExportRecordsAction
{
    protected static function job(): string
    {
        return ExportCashBook::class;
    }

    protected static function can(): bool
    {
        return TransactionResource::canExport();
    }

    protected static function qualifiedKey(): string
    {
        return 'transactions.id';
    }
}
