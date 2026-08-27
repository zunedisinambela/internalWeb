<?php

namespace App\Reports;

use App\Exports\CustomersExport;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * The customer directory: who buys, how much they have bought, and where the
 * free-item count stands for each of them.
 *
 * ## Three aggregates, three subqueries, no relation walked
 *
 * Orders placed, items bought and free items collected are asked for with
 * withCount()/withSum() rather than by walking the relations. On a screen that
 * is a query per row; in an export it is a query per row over the whole
 * directory. They arrive as **null, not 0**, for a customer with no orders —
 * which is why every figure below is cast before it is used, and why the free
 * item arithmetic goes through Customer::freeItemsFor(), whose signature takes
 * a nullable int for exactly this reason.
 *
 * ## Earned and collected are different kinds of fact
 *
 * `gratis_didapat` is derived from the orders; `gratis_diambil` is a recorded
 * handover. The two must not be merged and the difference between them is
 * deliberately not clamped at zero — a negative means a handover was recorded
 * against an order later corrected downwards, which is a real bookkeeping
 * problem that a max(0, …) would print as a customer who happens to be owed
 * nothing. Same rule as Customer::$free_quantity_available.
 *
 * ## No period
 *
 * A directory is ordered by name, not by time, so period() stays null and the
 * header prints a count instead of a date range. `created_at` is a column of
 * the report, not the axis it is sorted on: sorting by it would make the
 * printed order depend on the order rows were entered, which is not how anyone
 * looks somebody up.
 */
class CustomerReport extends Report
{
    private int $orders = 0;

    private int $quantity = 0;

    private int $earned = 0;

    private int $claimed = 0;

    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * @param  array<int, int>  $ids
     */
    public static function forIds(array $ids): static
    {
        return new static(Customer::query()->whereKey($ids));
    }

    public function query(): Builder
    {
        return $this->query
            ->clone()
            ->withCount('sales')
            ->withSum('sales', 'quantity')
            ->withSum('freeItemRedemptions', 'quantity')
            ->reorder()
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  Customer  $record
     * @return array{customer: Customer, orders: int, quantity: int, earned: int, claimed: int, outstanding: int}
     */
    public function fold(Model $record): array
    {
        $quantity = (int) $record->sales_sum_quantity;
        $earned = Customer::freeItemsFor($quantity);
        $claimed = (int) $record->free_item_redemptions_sum_quantity;

        $this->orders += (int) $record->sales_count;
        $this->quantity += $quantity;
        $this->earned += $earned;
        $this->claimed += $claimed;

        // No date: this report is a directory, not a timeline.
        $this->rowCounted();

        return [
            'customer' => $record,
            'orders' => (int) $record->sales_count,
            'quantity' => $quantity,
            'earned' => $earned,
            'claimed' => $claimed,
            'outstanding' => $earned - $claimed,
        ];
    }

    /**
     * @return array{orders: int, quantity: int, earned: int, claimed: int, outstanding: int, rows: int}
     */
    public function totals(): array
    {
        return [
            'orders' => $this->orders,
            'quantity' => $this->quantity,
            'earned' => $this->earned,
            'claimed' => $this->claimed,
            'outstanding' => $this->earned - $this->claimed,
            'rows' => $this->rowCount(),
        ];
    }

    public function excel(): FromQuery
    {
        return new CustomersExport($this);
    }

    public function view(): string
    {
        return 'pdf.pelanggan';
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
        return 'Pelanggan';
    }

    public static function unit(): string
    {
        return 'pelanggan';
    }

    public static function slug(): string
    {
        return 'pelanggan';
    }

    public static function event(): string
    {
        return 'customers_exported';
    }

    protected function reset(): void
    {
        parent::reset();

        $this->orders = 0;
        $this->quantity = 0;
        $this->earned = 0;
        $this->claimed = 0;
    }
}
