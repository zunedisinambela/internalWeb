<?php

namespace App\Exports;

use App\Models\Sale;
use App\Reports\SalesReport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The sales log as a spreadsheet.
 *
 * The three prices reach their cells as plain numbers so the reader can add a
 * column of them; the margin is written out rather than left as a formula,
 * because a formula would refer to cells the reader is free to reorder. What
 * the figures mean belongs to App\Reports\SalesReport; how a sheet is dressed
 * belongs to App\Exports\ReportExport.
 *
 * Attachments are counted here rather than embedded. A spreadsheet cell holding
 * a photograph is a floating drawing anchored to a cell — it does not move when
 * the row is sorted, and it does not survive a CSV round trip. The PDF is where
 * the evidence is printed; this file is where the figures are.
 */
class SalesExport extends ReportExport
{
    public function __construct(SalesReport $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Tanggal',
            'Pelanggan',
            'Jumlah',
            'Harga market',
            'Ongkir',
            'Harga katalog',
            'Keuntungan',
            'Bukti transfer',
            'Resi',
            'Catatan',
            'Dicatat oleh',
        ];
    }

    /**
     * @param  array{sale: Sale, profit: int}  $line
     * @return array<int, mixed>
     */
    protected function cells(array $line): array
    {
        $sale = $line['sale'];

        return [
            // A real Excel date rather than a formatted string, so the column
            // sorts and filters as a date. occurred_at is already WIB.
            ExcelDate::dateTimeToExcel($sale->occurred_at),
            $sale->customer?->name,
            $sale->quantity,
            $sale->marketing_price,
            $sale->shipping_cost,
            $sale->catalog_price,
            $line['profit'],
            // Counted off the already-loaded relation, so this is not a query
            // per row — SalesReport::query() eager-loads both collections.
            $sale->media->where('collection_name', Sale::PAYMENT_PROOFS)->count(),
            $sale->media->where('collection_name', Sale::SHIPPING_PROOFS)->count(),
            $sale->note,
            $sale->user?->name,
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
            $totals['quantity'],
            $totals['marketing'],
            $totals['shipping'],
            $totals['catalog'],
            $totals['profit'],
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
        return ['D', 'E', 'F', 'G'];
    }

    /**
     * @return array<int, string>
     */
    protected function dateTimeColumns(): array
    {
        return ['A'];
    }

    /**
     * @return array<int, string>
     */
    protected function centeredColumns(): array
    {
        return ['C', 'H', 'I'];
    }
}
