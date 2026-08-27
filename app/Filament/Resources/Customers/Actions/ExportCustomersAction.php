<?php

namespace App\Filament\Resources\Customers\Actions;

use App\Filament\Actions\ExportRecordsAction;
use App\Filament\Resources\Customers\CustomerResource;
use App\Jobs\ExportCustomers;

/**
 * The customer directory, as filtered on screen, as a spreadsheet or a PDF.
 *
 * The file carries home addresses, which is why the gate below is the customer
 * policy rather than anything looser, and why the job audits the download. See
 * Access control.
 *
 * The qualified key matters more here than elsewhere: the customer table adds a
 * withSum subquery for the free-item count, so `id` alone is ambiguous the
 * moment a second one is added.
 */
class ExportCustomersAction extends ExportRecordsAction
{
    protected static function job(): string
    {
        return ExportCustomers::class;
    }

    protected static function can(): bool
    {
        return CustomerResource::canExport();
    }

    protected static function qualifiedKey(): string
    {
        return 'customers.id';
    }
}
