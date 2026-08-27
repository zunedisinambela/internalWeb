<?php

namespace App\Exports;

use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A report as a spreadsheet: headings, one row per record, a totals row, and
 * the number formats that make the figures add up in Excel rather than sit
 * there as text.
 *
 * A subclass says what the columns are called, how one folded line becomes
 * cells, and which of those columns hold money or dates. Everything below that
 * — the header fill, the frozen pane, the appended totals row and its border,
 * the auto-sizing — is the same on every sheet in this panel and is written
 * once here.
 *
 * ## Do not add ShouldQueue
 *
 * A Report accumulates its totals as map() walks the rows, so one instance has
 * to see every row in order. Laravel-Excel's queueing splits the query into
 * chunks and runs each in its own job with its own instance: every chunk would
 * restart the accumulation at its first row, and the file would still look
 * entirely plausible.
 *
 * The rendering *is* off the request, and this is untouched by that.
 * App\Jobs\ExportReport queues the whole render as one job and calls this class
 * inside it — one job, one Report, one pass. That is the arrangement this
 * concern needs, and adding ShouldQueue here would break it again from the
 * inside. If an export ever has to chunk across jobs, move the running totals
 * into SQL window functions first.
 *
 * ## WithStrictNullComparison is load-bearing
 *
 * Rows are written through Worksheet::fromArray(), which skips any cell equal
 * to its $nullValue — and with the default loose comparison `0 != null` is
 * false, so every zero in the file would be dropped. Without this concern a
 * receipt count of 0 comes out blank rather than 0, and a filtered book's empty
 * side totals to nothing at all.
 *
 * With it, the two cases stay distinct on purpose: null means "this column does
 * not apply to this row" and prints an empty cell, 0 means a genuine zero and
 * prints one.
 */
abstract class ReportExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * Whole rupiah, so `#,##0` never needs decimals. Excel substitutes the
     * viewer's own separators into a format code, so this renders as 1.500.000
     * on an Indonesian machine and 1,500,000 elsewhere — the file does not have
     * to guess where it will be opened.
     */
    protected const MONEY = '"Rp" #,##0';

    protected const DATETIME = 'dd/mm/yyyy hh:mm';

    protected const DATE = 'dd/mm/yyyy';

    public function __construct(
        protected readonly Report $report,
    ) {}

    /**
     * @return array<int, string>
     */
    abstract public function headings(): array;

    /**
     * One folded line as spreadsheet cells, in heading order.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, mixed>
     */
    abstract protected function cells(array $line): array;

    /**
     * The TOTAL row, in heading order, or null for a report that does not have
     * one — a directory of customers has nothing to add up in the way a ledger
     * does.
     *
     * It cannot come out of cells(), which only ever sees one record: it is
     * appended once the last one has been written.
     *
     * @return array<int, mixed>|null
     */
    abstract protected function totalsRow(): ?array;

    public function query(): Builder
    {
        return $this->report->query();
    }

    /**
     * @return array<int, mixed>
     */
    public function map(mixed $row): array
    {
        /** @var Model $row */
        return $this->cells($this->report->fold($row));
    }

    public function title(): string
    {
        return $this->report::label();
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->moneyColumns() as $column) {
            $formats[$column] = self::MONEY;
        }

        foreach ($this->dateTimeColumns() as $column) {
            $formats[$column] = self::DATETIME;
        }

        foreach ($this->dateColumns() as $column) {
            $formats[$column] = self::DATE;
        }

        return $formats;
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
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = $this->lastColumn();

                $this->styleHeader($sheet, $last);

                $bodyEnd = $sheet->getHighestRow();

                foreach ($this->centeredColumns() as $column) {
                    $sheet->getStyle("{$column}2:{$column}{$bodyEnd}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $totals = $this->totalsRow();

                if ($totals === null) {
                    return;
                }

                $row = $bodyEnd + 1;

                // The trailing `true` is strictNullComparison, for the same
                // reason the class implements the concern of that name — this
                // call bypasses it by writing to the sheet directly. Without it
                // a column totalling zero, which is what any one-directional
                // filter produces, prints an empty cell.
                $sheet->fromArray($totals, null, "A{$row}", true);

                $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => [
                        'top' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                ]);

                // Applied after the row exists; columnFormats() only covers the
                // mapped rows, so without this the totals print unformatted.
                foreach ($this->moneyColumns() as $column) {
                    $sheet->getStyle("{$column}{$row}")
                        ->getNumberFormat()
                        ->setFormatCode(self::MONEY);
                }
            },
        ];
    }

    public function rowCount(): int
    {
        return $this->report->rowCount();
    }

    /**
     * Columns holding whole rupiah.
     *
     * @return array<int, string>
     */
    protected function moneyColumns(): array
    {
        return [];
    }

    /**
     * Columns holding a real Excel date-time, so the column sorts and filters
     * as a date in the spreadsheet rather than as a string.
     *
     * @return array<int, string>
     */
    protected function dateTimeColumns(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function dateColumns(): array
    {
        return [];
    }

    /**
     * Columns of small counts, which read better centred than ranged left.
     *
     * @return array<int, string>
     */
    protected function centeredColumns(): array
    {
        return [];
    }

    /**
     * The rightmost column letter, derived from the headings rather than
     * written down — a column added to headings() and forgotten here would
     * leave the header fill and the totals border one cell short, which is a
     * cosmetic bug nobody reports and nobody can find.
     */
    protected function lastColumn(): string
    {
        return Coordinate::stringFromColumnIndex(count($this->headings()));
    }

    private function styleHeader(Worksheet $sheet, string $last): void
    {
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF3F4F6'],
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Keeps the headings visible while scrolling a long sheet.
        $sheet->freezePane('A2');
    }
}
