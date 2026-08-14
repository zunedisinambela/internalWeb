<?php

namespace App\Reports;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 * table at /admin/transactions defaults to `occurred_at desc`, which is how a
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
 */
class CashBook
{
    private int $balance = 0;

    private int $income = 0;

    private int $expense = 0;

    private int $rows = 0;

    /** Bounds of what was actually folded, for the report header. */
    private ?Carbon $first = null;

    private ?Carbon $last = null;

    public function __construct(
        private readonly Builder $query,
    ) {}

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
     * @return array{transaction: Transaction, income: int|null, expense: int|null, balance: int, receipts: int}
     */
    public function fold(Transaction $transaction): array
    {
        $income = $transaction->type === TransactionType::Income ? $transaction->amount : null;
        $expense = $transaction->type === TransactionType::Expense ? $transaction->amount : null;

        $this->balance += $transaction->amount * $transaction->type->sign();
        $this->income += $income ?? 0;
        $this->expense += $expense ?? 0;
        $this->rows++;

        // Rows arrive in ledger order, so the first one folded opens the period
        // and every one after it closes it.
        $this->first ??= $transaction->occurred_at;
        $this->last = $transaction->occurred_at;

        return [
            'transaction' => $transaction,
            'income' => $income,
            'expense' => $expense,
            'balance' => $this->balance,
            'receipts' => (int) $transaction->receipts_count,
        ];
    }

    /**
     * Every line at once, for a renderer that cannot stream — dompdf builds one
     * HTML string, so the PDF report has to hold the book in memory anyway.
     *
     * @return Collection<int, array{transaction: Transaction, income: int|null, expense: int|null, balance: int, receipts: int}>
     */
    public function lines(): Collection
    {
        $this->reset();

        return $this->query()
            ->get()
            ->map(fn (Transaction $transaction): array => $this->fold($transaction));
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
            'rows' => $this->rows,
        ];
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    /**
     * The period the folded lines actually cover — taken from the rows rather
     * than from the filter that selected them, because the two differ: a filter
     * reading "sejak 1 Agustus" on a month with three entries should print the
     * range those three span, not the open-ended request. Null when nothing was
     * folded, which is what an empty book has to say instead of a date range.
     *
     * Read off the fold rather than queried again, so it costs nothing and
     * cannot disagree with the lines above it.
     *
     * @return array{from: Carbon, until: Carbon}|null
     */
    public function period(): ?array
    {
        if ($this->first === null || $this->last === null) {
            return null;
        }

        return ['from' => $this->first, 'until' => $this->last];
    }

    private function reset(): void
    {
        $this->balance = 0;
        $this->income = 0;
        $this->expense = 0;
        $this->rows = 0;
        $this->first = null;
        $this->last = null;
    }
}
