<?php

namespace App\Filament\Resources\Sales\Actions;

use App\Filament\Actions\ExportRecordsAction;
use App\Filament\Resources\Sales\SaleResource;
use App\Jobs\ExportSales;

/**
 * The sales log, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Everything about how an export works lives on
 * App\Filament\Actions\ExportRecordsAction. Read that class before changing
 * anything here.
 */
class ExportSalesAction extends ExportRecordsAction
{
    protected static function job(): string
    {
        return ExportSales::class;
    }

    protected static function can(): bool
    {
        return SaleResource::canExport();
    }

    protected static function qualifiedKey(): string
    {
        return 'sales.id';
    }
}
