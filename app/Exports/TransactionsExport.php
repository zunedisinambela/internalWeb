<?php

namespace App\Exports;

use App\Models\Transaction;
use App\Reports\CashBook;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The cash book as a two-column ledger: money in, money out, running balance.
 *
 * The screen at /transactions puts a single signed amount in one column,
 * because a `+` or `−` in front of the figure is the fastest way to read a
 * direction off a list. A spreadsheet cannot do that and stay useful: the sign
 * would have to be part of a string, and a string cannot be summed. So the
 * direction moves into the column layout instead, and every figure reaches the
 * cell as a number that Excel will add up.
 *
 * That is the whole reason this class is not a straight mirror of the table.
 *
 * What the book *says* — ordering, the running balance, the totals — belongs to
 * App\Reports\CashBook, which the PDF report reads as well. This class only
 * decides how those figures reach a spreadsheet cell. How a sheet is dressed —
 * the header fill, the frozen pane, the totals border — belongs to
 * App\Exports\ReportExport, which every export here extends.
 */
class TransactionsExport extends ReportExport
{
    public function __construct(CashBook $book)
    {
        parent::__construct($book);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Waktu',
            'Keterangan',
            'Pemasukan',
            'Pengeluaran',
            'Saldo',
            'Bukti',
            'Dicatat oleh',
        ];
    }

    /**
     * @param  array{transaction: Transaction, income: int|null, expense: int|null, balance: int, receipts: int}  $line
     * @return array<int, mixed>
     */
    protected function cells(array $line): array
    {
        $transaction = $line['transaction'];

        return [
            // A real Excel date rather than a formatted string, so the column
            // sorts and filters as a date in the spreadsheet. occurred_at is
            // already WIB — see Locale and timezone — so nothing is converted.
            ExcelDate::dateTimeToExcel($transaction->occurred_at),
            $transaction->description,
            // null, not 0: an empty cell reads as "not this side of the book",
            // where Rp 0 reads as a transaction that moved nothing. Preserving
            // that distinction through to the file is what
            // WithStrictNullComparison is for.
            $line['income'],
            $line['expense'],
            $line['balance'],
            $line['receipts'],
            $transaction->user?->name,
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
            $totals['income'],
            $totals['expense'],
            $totals['balance'],
            null,
            null,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function moneyColumns(): array
    {
        return ['C', 'D', 'E'];
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
        return ['F'];
    }
}
