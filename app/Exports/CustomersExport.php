<?php

namespace App\Exports;

use App\Models\Customer;
use App\Reports\CustomerReport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The customer directory as a spreadsheet.
 *
 * No money columns. The margin a customer has produced is on the customer's own
 * view screen, walked from their orders in PHP; asking for it here would mean a
 * second copy of that arithmetic as SQL, and two figures able to disagree. What
 * this file carries is the counting: orders, items, and where the free-item
 * tally stands.
 *
 * ## It carries home addresses
 *
 * `address` is a column of this export, which makes the file a list of where
 * people live — the same exposure the panel gates behind View:Customer, in a
 * form that leaves the building. That is the whole reason the download is
 * authorized against the resource and audited by the job. See Access control.
 */
class CustomersExport extends ReportExport
{
    public function __construct(CustomerReport $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Nama',
            'Telepon',
            'Alamat',
            'Transaksi',
            'Barang',
            'Gratis didapat',
            'Gratis diambil',
            'Sisa gratis',
            'Status',
            'Catatan',
            'Ditambahkan',
        ];
    }

    /**
     * @param  array{customer: Customer, orders: int, quantity: int, earned: int, claimed: int, outstanding: int}  $line
     * @return array<int, mixed>
     */
    protected function cells(array $line): array
    {
        $customer = $line['customer'];

        return [
            $customer->name,
            $customer->phone,
            $customer->address,
            $line['orders'],
            $line['quantity'],
            $line['earned'],
            $line['claimed'],
            $line['outstanding'],
            $customer->is_active ? 'Aktif' : 'Tidak aktif',
            $customer->note,
            ExcelDate::dateTimeToExcel($customer->created_at),
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
            null,
            $totals['orders'],
            $totals['quantity'],
            $totals['earned'],
            $totals['claimed'],
            $totals['outstanding'],
            null,
            null,
            null,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function dateColumns(): array
    {
        return ['K'];
    }

    /**
     * @return array<int, string>
     */
    protected function centeredColumns(): array
    {
        return ['D', 'E', 'F', 'G', 'H'];
    }
}
