<?php

namespace App\Exports;

use App\Models\Transaction;
use App\Reports\CashBook;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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
 * decides how those figures reach a spreadsheet cell.
 *
 * ## Do not add ShouldQueue — the export is still synchronous
 *
 * The balance accumulates in the CashBook as map() walks the rows, so one
 * instance has to see every row in order. Laravel-Excel's queueing splits the
 * query into chunks and runs each in its own job with its own instance: every
 * chunk would restart the balance at its first row, and the file would still
 * look entirely plausible.
 *
 * The rendering *is* off the request now, and this is untouched by that.
 * App\Jobs\ExportCashBook queues the whole render as one job and calls this
 * class inside it — one job, one CashBook, one pass. That is the arrangement
 * this concern needs, and adding ShouldQueue here would break it again from
 * the inside. If the export itself ever has to chunk across jobs, move the
 * running total into a SQL window function first.
 *
 * ## WithStrictNullComparison is load-bearing
 *
 * Rows are written through Worksheet::fromArray(), which skips any cell equal
 * to its $nullValue — and with the default loose comparison `0 != null` is
 * false, so every zero in the file would be dropped. Without this concern the
 * receipt count on a transaction with no receipts comes out blank rather than
 * 0, and a filtered book's empty side totals to nothing at all.
 *
 * With it, the two cases stay distinct on purpose: null means "not this side of
 * the book" and prints an empty cell, 0 means a genuine zero and prints one.
 */
class TransactionsExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * Whole rupiah, so `#,##0` never needs decimals. Excel substitutes the
     * viewer's own separators into a format code, so this renders as 1.500.000
     * on an Indonesian machine and 1,500,000 elsewhere — the file does not have
     * to guess where it will be opened.
     */
    private const MONEY = '"Rp" #,##0';

    private const DATETIME = 'dd/mm/yyyy hh:mm';

    public function __construct(
        private readonly CashBook $book,
    ) {}

    public function query(): Builder
    {
        return $this->book->query();
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
     * @return array<int, mixed>
     */
    public function map(mixed $row): array
    {
        /** @var Transaction $row */
        $line = $this->book->fold($row);

        return [
            // A real Excel date rather than a formatted string, so the column
            // sorts and filters as a date in the spreadsheet. occurred_at is
            // already WIB — see Locale and timezone — so nothing is converted.
            ExcelDate::dateTimeToExcel($row->occurred_at),
            $row->description,
            // null, not 0: an empty cell reads as "not this side of the book",
            // where Rp 0 reads as a transaction that moved nothing. Preserving
            // that distinction through to the file is what
            // WithStrictNullComparison is for.
            $line['income'],
            $line['expense'],
            $line['balance'],
            $line['receipts'],
            $row->user?->name,
        ];
    }

    public function title(): string
    {
        return 'Buku Kas';
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'A' => self::DATETIME,
            'C' => self::MONEY,
            'D' => self::MONEY,
            'E' => self::MONEY,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            // The totals row cannot come out of map(), which only ever sees one
            // record — it is appended once the last one has been written.
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $this->styleHeader($sheet);

                $total = $sheet->getHighestRow() + 1;

                // The trailing `true` is strictNullComparison, for the same
                // reason the class implements the concern of that name — this
                // call bypasses it by writing to the sheet directly. Without
                // it a side of the book totalling zero, which is what any
                // one-directional filter produces, prints an empty cell.
                $totals = $this->book->totals();

                $sheet->fromArray([
                    'TOTAL',
                    null,
                    $totals['income'],
                    $totals['expense'],
                    $totals['balance'],
                    null,
                    null,
                ], null, "A{$total}", true);

                $sheet->getStyle("A{$total}:G{$total}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => [
                        'top' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                ]);

                // Applied after the row exists; columnFormats() only covers the
                // mapped rows, so without this the totals print unformatted.
                $sheet->getStyle("C{$total}:E{$total}")
                    ->getNumberFormat()
                    ->setFormatCode(self::MONEY);

                $sheet->getStyle("F2:F{$total}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }

    public function rowCount(): int
    {
        return $this->book->rowCount();
    }

    private function styleHeader(Worksheet $sheet): void
    {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF3F4F6'],
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Keeps the headings visible while scrolling a long book.
        $sheet->freezePane('A2');
    }
}
