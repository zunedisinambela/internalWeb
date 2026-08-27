<?php

namespace App\Reports;

use App\Exports\MeterReadingsExport;
use App\Models\MeterReading;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * The meter log: one line per period, the two dial figures that bound it, what
 * was consumed between them and what was paid for it.
 *
 * ## Nothing here multiplies anything
 *
 * The panel records a bill, it does not compute one — see the Listrik kost
 * documentation. `total_amount` is typed off the bill and `usage_kwh` is the
 * difference between the two dial figures, and no rate connects them. So the
 * footer adds up two independent columns; a "harga per kWh" derived by dividing
 * one by the other would be a figure this project deliberately does not hold,
 * arrived at by inference.
 *
 * ## Ordered by when the period closed
 *
 * Everything here compares `end_read_at`, never `start_read_at`, for the reason
 * MeterReading::previous() gives: a reading covers a period and is placed on
 * the timeline by where that period closes, so ordering on the opening moment
 * would let a short reading taken inside a long one print as the later of the
 * two.
 */
class MeterReadingReport extends Report
{
    private int $usage = 0;

    private int $amount = 0;

    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * @param  array<int, int>  $ids
     */
    public static function forIds(array $ids): static
    {
        return new static(MeterReading::query()->whereKey($ids));
    }

    public function query(): Builder
    {
        return $this->query
            ->clone()
            ->with('user')
            // The dial photographs, for the PDF. Both collections in one eager
            // load; the view separates them again by collection_name, which is
            // the whole reason they are two collections rather than one — a
            // photograph says for itself which figure it backs.
            ->with(['media' => fn ($q) => $q->whereIn('collection_name', [MeterReading::PHOTOS_START, MeterReading::PHOTOS_END])])
            ->reorder()
            ->orderBy('end_read_at')
            ->orderBy('id');
    }

    /**
     * @param  MeterReading  $record
     * @return array{reading: MeterReading, usage: int}
     */
    public function fold(Model $record): array
    {
        $usage = $record->usage_kwh;

        $this->usage += $usage;
        $this->amount += $record->total_amount;

        $this->rowCounted($record->end_read_at);

        return [
            'reading' => $record,
            'usage' => $usage,
        ];
    }

    /**
     * @return array{usage: int, amount: int, rows: int}
     */
    public function totals(): array
    {
        return [
            'usage' => $this->usage,
            'amount' => $this->amount,
            'rows' => $this->rowCount(),
        ];
    }

    public function excel(): FromQuery
    {
        return new MeterReadingsExport($this);
    }

    public function view(): string
    {
        return 'pdf.meteran-listrik';
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $lines = $this->lines();

        return [
            'lines' => $lines,
            'totals' => $this->totals(),
            'period' => $this->period(),
        ];
    }

    public static function label(): string
    {
        return 'Meteran Listrik';
    }

    public static function unit(): string
    {
        return 'pembacaan';
    }

    public static function slug(): string
    {
        return 'meteran-listrik';
    }

    public static function event(): string
    {
        return 'meter_readings_exported';
    }

    protected function reset(): void
    {
        parent::reset();

        $this->usage = 0;
        $this->amount = 0;
    }
}
