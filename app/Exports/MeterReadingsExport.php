<?php

namespace App\Exports;

use App\Models\MeterReading;
use App\Reports\MeterReadingReport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The meter log as a spreadsheet.
 *
 * Both dial figures are printed beside the difference between them rather than
 * only the difference, because a disputed bill is settled by comparing the
 * opening figure against the photograph taken when the period opened — and the
 * photographs are in the PDF, keyed to those two numbers.
 *
 * There is no rate column and no rate row. The panel records a bill rather than
 * computing one, so a "per kWh" figure here would be arrived at by dividing two
 * columns this project deliberately keeps independent. See
 * App\Reports\MeterReadingReport.
 */
class MeterReadingsExport extends ReportExport
{
    public function __construct(MeterReadingReport $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Pembacaan awal',
            'Pembacaan akhir',
            'kWh awal',
            'kWh akhir',
            'Pemakaian',
            'Tagihan',
            'Foto awal',
            'Foto akhir',
            'Catatan',
            'Dicatat oleh',
        ];
    }

    /**
     * @param  array{reading: MeterReading, usage: int}  $line
     * @return array<int, mixed>
     */
    protected function cells(array $line): array
    {
        $reading = $line['reading'];

        return [
            ExcelDate::dateTimeToExcel($reading->start_read_at),
            ExcelDate::dateTimeToExcel($reading->end_read_at),
            $reading->start_kwh,
            $reading->end_kwh,
            $line['usage'],
            $reading->total_amount,
            $reading->media->where('collection_name', MeterReading::PHOTOS_START)->count(),
            $reading->media->where('collection_name', MeterReading::PHOTOS_END)->count(),
            $reading->note,
            $reading->user?->name,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function totalsRow(): array
    {
        $totals = $this->report->totals();

        return [
            'TOTAL',
            null,
            // The dial figures are deliberately absent: they are meter
            // positions, and a column of positions has no sum. Only what was
            // consumed and what was paid do.
            null,
            null,
            $totals['usage'],
            $totals['amount'],
            null,
            null,
            null,
            null,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function moneyColumns(): array
    {
        return ['F'];
    }

    /**
     * @return array<int, string>
     */
    protected function dateTimeColumns(): array
    {
        return ['A', 'B'];
    }

    /**
     * @return array<int, string>
     */
    protected function centeredColumns(): array
    {
        return ['C', 'D', 'E', 'G', 'H'];
    }
}
