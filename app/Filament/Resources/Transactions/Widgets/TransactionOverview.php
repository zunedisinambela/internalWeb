<?php

namespace App\Filament\Resources\Transactions\Widgets;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Money in, money out and what is left, above the transaction list.
 *
 * It lives under the resource rather than in app/Filament/Widgets because the
 * panel provider calls discoverWidgets() on that directory, and everything it
 * finds there lands on the dashboard. The dashboard is deliberately limited to
 * AccountWidget, and it is reachable by anyone holding any role — while these
 * figures are gated by the transaction policy. Keeping the file here means the
 * only way to see it is through the screen that already checks.
 */
class TransactionOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        // One pass over the table for both totals rather than two aggregate
        // queries. SUM over a CASE returns null on an empty table, hence the
        // coalesce — without it the first page of a fresh install renders an
        // empty stat instead of zero.
        $totals = Transaction::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS income', [TransactionType::Income->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS expense', [TransactionType::Expense->value])
            ->first();

        $income = (int) $totals->income;
        $expense = (int) $totals->expense;
        $balance = $income - $expense;

        return [
            Stat::make('Total pemasukan', self::rupiah($income))
                ->description('Seluruh transaksi masuk')
                ->descriptionIcon(Heroicon::ArrowDownLeft)
                ->color('success'),

            Stat::make('Total pengeluaran', self::rupiah($expense))
                ->description('Seluruh transaksi keluar')
                ->descriptionIcon(Heroicon::ArrowUpRight)
                ->color('danger'),

            Stat::make('Saldo', self::rupiah($balance))
                ->description($balance < 0 ? 'Pengeluaran melebihi pemasukan' : 'Pemasukan dikurangi pengeluaran')
                ->descriptionIcon($balance < 0 ? Heroicon::ArrowTrendingDown : Heroicon::ArrowTrendingUp)
                ->color($balance < 0 ? 'danger' : 'primary'),
        ];
    }

    /**
     * Indonesian grouping: a full stop every three digits, no decimals. The
     * column is whole rupiah, so there is nothing after a separator to show.
     */
    protected static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
