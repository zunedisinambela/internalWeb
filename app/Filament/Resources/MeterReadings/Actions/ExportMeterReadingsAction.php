<?php

namespace App\Filament\Resources\MeterReadings\Actions;

use App\Filament\Actions\ExportRecordsAction;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Jobs\ExportMeterReadings;

/**
 * The meter log, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Everything about how an export works lives on
 * App\Filament\Actions\ExportRecordsAction. Read that class before changing
 * anything here.
 */
class ExportMeterReadingsAction extends ExportRecordsAction
{
    protected static function job(): string
    {
        return ExportMeterReadings::class;
    }

    protected static function can(): bool
    {
        return MeterReadingResource::canExport();
    }

    protected static function qualifiedKey(): string
    {
        return 'meter_readings.id';
    }
}
