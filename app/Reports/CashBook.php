<?php

namespace App\Reports;

use App\Enums\TransactionType;
use App\Exports\TransactionsExport;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * The cash book as a ledger: one line per transaction, money in and money out
 * in their own columns, with a running balance beside them.
 *
 * Two renderers read this — the spreadsheet export and the PDF report — and
 * neither of them is allowed to have its own opinion about what the book says.
 * Ordering, the running balance and the totals live here so a figure cannot
 * disagree between the two files someone downloads five seconds apart.
 *
 * ## Ordering is imposed, never inherited
 *
 * `saldo` is a running total, so it only means anything read oldest-first. The
 * table at /transactions defaults to `occurred_at desc`, which is how a
 * cash book is *read* and the reverse of how it is *accumulated* — so callers
 * pass their filtered query and this reorders it.
 *
 * `id` is the tiebreak, and it is not decorative. Laravel-Excel's FromQuery
 * paginates the query to chunk it, so two rows sharing an `occurred_at` could
 * otherwise land on different pages in a different relative order each time,
 * silently repeating one and dropping another across the boundary.
 *
 * ## One instance, one pass
 *
 * fold() advances the running balance on every call, so an instance describes
 * exactly one walk through the book. Iterating twice would double every total.
 * lines() is the eager equivalent for renderers that cannot stream, and it
 * resets first so the two entry points cannot be mixed by accident.
 *
 * The row counter and the period bounds live on App\Reports\Report, which every
 * downloadable report in this panel extends; the running balance is this class's
 * own, because nothing else here accumulates one.
 */
class CashBook extends Report
{
    private int $balance = 0;

    private int $income = 0;

    private int $expense = 0;

    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * The filtered set as a report, addressed by primary key.
     *
     * This is what the queued job builds: a Builder cannot travel on a queue —
     * it holds a Connection, a Connection holds a PDO handle, and PDO refuses
     * to serialize — so the filtered rows are resolved to ids at dispatch and
     * the query is rebuilt here.
     *
     * @param  array<int, int>  $ids
     */
    public static function forIds(array $ids): static
    {
        return new static(Transaction::query()->whereKey($ids));
    }

    /**
     * The caller's filters, in ledger order, with the relations both renderers
     * put on the page.
     */
    public function query(): Builder
    {
        return $this->query
            ->clone()
            // The recorder's name is a column of the output; without this it is
            // a query per row.
            ->with('user')
            // The receipts themselves, for the PDF — the Bukti column prints
            // the photographs rather than how many there are. Constrained to
            // the receipts collection so the relation holds nothing else, which
            // is what lets the view call getMedia() on an already-loaded
            // relation without going back to the database per row.
            //
            // The spreadsheet does not use them and pays for them anyway: one
            // extra query per chunk, not per row, because this is an eager load
            // rather than a lazy one. That is cheaper than a second query()
            // whose eager loads could drift from this one.
            ->with(['media' => fn ($q) => $q->where('collection_name', Transaction::RECEIPTS)])
            // Scoped to the receipts collection rather than counting `media`
            // outright: today that is the only collection on this model, and a
            // second one added later must not quietly inflate the column.
            ->withCount(['media as receipts_count' => fn (Builder $q) => $q->where('collection_name', Transaction::RECEIPTS)])
            ->reorder()
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Fold one transaction into the ledger and hand back its line.
     *
     * `income` and `expense` are null rather than 0 on the side that does not
     * apply. The distinction is deliberate and both renderers depend on it: an
     * empty cell reads as "not this side of the book", where a zero reads as a
     * transaction that moved nothing.
     *
     * @param  Transaction  $record
     * @return array{transaction: Transaction, income: int|null, expense: int|null, balance: int, receipts: int}
     */
    public function fold(Model $record): array
    {
        $income = $record->type === TransactionType::Income ? $record->amount : null;
        $expense = $record->type === TransactionType::Expense ? $record->amount : null;

        $this->balance += $record->amount * $record->type->sign();
        $this->income += $income ?? 0;
        $this->expense += $expense ?? 0;

        $this->rowCounted($record->occurred_at);

        return [
            'transaction' => $record,
            'income' => $income,
            'expense' => $expense,
            'balance' => $this->balance,
            'receipts' => (int) $record->receipts_count,
        ];
    }

    /**
     * @return array{income: int, expense: int, balance: int, rows: int}
     */
    public function totals(): array
    {
        return [
            'income' => $this->income,
            'expense' => $this->expense,
            'balance' => $this->balance,
            'rows' => $this->rowCount(),
        ];
    }

    public function excel(): FromQuery
    {
        return new TransactionsExport($this);
    }

    public function view(): string
    {
        return 'pdf.buku-kas';
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        // lines() first: totals() and period() are accumulated by the fold and
        // are only final once every line has been walked.
        $lines = $this->lines();

        return [
            'lines' => $lines,
            'totals' => $this->totals(),
            'period' => $this->period(),
        ];
    }

    public static function label(): string
    {
        return 'Buku Kas';
    }

    public static function unit(): string
    {
        return 'transaksi';
    }

    public static function slug(): string
    {
        return 'buku-kas';
    }

    public static function event(): string
    {
        return 'transactions_exported';
    }

    protected function reset(): void
    {
        parent::reset();

        $this->balance = 0;
        $this->income = 0;
        $this->expense = 0;
    }
}
