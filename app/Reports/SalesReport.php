<?php

namespace App\Reports;

use App\Exports\SalesExport;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * The sales log: one line per order, with what it cost, what it sold for and
 * what was left.
 *
 * ## The three prices are totals, and the margin is derived from them
 *
 * `marketing_price`, `shipping_cost` and `catalog_price` are figures for the
 * whole order — `quantity` counts items and reprices nothing. So the footer
 * sums each of the three and derives the margin from those sums rather than
 * summing a per-row margin: the two agree here because the margin is linear,
 * and deriving it is what keeps them agreeing if it ever stops being.
 *
 * The margin is not clamped at zero, exactly as Sale::$profit is not. An order
 * posted a long way can genuinely lose money, and printing that as a negative
 * is how it becomes visible; max(0, …) would print the same order as one that
 * happened to earn nothing.
 *
 * ## The bonus is not in this report
 *
 * A free item is earned per *customer* across every order, not per sale — see
 * the Oriflame documentation. Printing a per-order figure here would answer the
 * same question with a smaller number on every row. It is on the customer
 * report, where the total it is counted from lives.
 */
class SalesReport extends Report
{
    private int $quantity = 0;

    private int $marketing = 0;

    private int $shipping = 0;

    private int $catalog = 0;

    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * @param  array<int, int>  $ids
     */
    public static function forIds(array $ids): static
    {
        return new static(Sale::query()->whereKey($ids));
    }

    public function query(): Builder
    {
        return $this->query
            ->clone()
            // Both are columns of the output; without this each is a query per
            // row. `user` is nullable — deleting an account must not delete the
            // orders it recorded.
            ->with(['customer', 'user'])
            // The attachments themselves, for the PDF: the two evidence columns
            // print the photographs rather than how many there are. Constrained
            // to the two collections this model registers so the relation holds
            // nothing else, which is what lets the view read it per row without
            // going back to the database.
            ->with(['media' => fn ($q) => $q->whereIn('collection_name', [Sale::PAYMENT_PROOFS, Sale::SHIPPING_PROOFS])])
            ->reorder()
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * @param  Sale  $record
     * @return array{sale: Sale, profit: int}
     */
    public function fold(Model $record): array
    {
        $this->quantity += $record->quantity;
        $this->marketing += $record->marketing_price;
        $this->shipping += $record->shipping_cost;
        $this->catalog += $record->catalog_price;

        $this->rowCounted($record->occurred_at);

        return [
            'sale' => $record,
            'profit' => $record->profit,
        ];
    }

    /**
     * @return array{quantity: int, marketing: int, shipping: int, catalog: int, profit: int, rows: int}
     */
    public function totals(): array
    {
        return [
            'quantity' => $this->quantity,
            'marketing' => $this->marketing,
            'shipping' => $this->shipping,
            'catalog' => $this->catalog,
            'profit' => $this->catalog - $this->marketing - $this->shipping,
            'rows' => $this->rowCount(),
        ];
    }

    public function excel(): FromQuery
    {
        return new SalesExport($this);
    }

    public function view(): string
    {
        return 'pdf.penjualan';
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
        return 'Penjualan';
    }

    public static function unit(): string
    {
        return 'penjualan';
    }

    public static function slug(): string
    {
        return 'penjualan';
    }

    public static function event(): string
    {
        return 'sales_exported';
    }

    protected function reset(): void
    {
        parent::reset();

        $this->quantity = 0;
        $this->marketing = 0;
        $this->shipping = 0;
        $this->catalog = 0;
    }
}
