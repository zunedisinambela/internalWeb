<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Filament\Resources\Transactions\TransactionResource;
use App\Jobs\ExportCashBook;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Asks for the cash book, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Both formats are here rather than in a class each because everything that
 * matters about them is shared: who may do it, which rows they get, and the
 * job that renders them. Only the file extension differs. Two copies of an
 * authorization rule is one copy too many.
 *
 * ## It queues, it does not download
 *
 * The render happens in App\Jobs\ExportCashBook, off the request. So this
 * action returns nothing at all — no BinaryFileResponse, no StreamedResponse —
 * and the finished file reaches the user as a database notification carrying a
 * signed link. The queue connection is `database`, so a deploy with no worker
 * leaves the job sitting in the table and the notification never arrives; the
 * same trap medialibrary conversions have. `php artisan dev` runs queue:listen.
 *
 * ## It exports the filtered set, not the page
 *
 * getFilteredTableQuery() gives the query with the caller's filters applied and
 * no pagination, so a filtered screen produces a filtered file. Its sibling
 * getFilteredSortedTableQuery() would also carry the table's sort, which is
 * exactly what must not happen — see App\Reports\CashBook for why the ledger
 * imposes chronological order.
 *
 * The rows are resolved to ids here rather than passing the query itself: a
 * builder cannot be serialized onto a queue, since it holds a PDO handle. See
 * the job for what that costs.
 *
 * ## It is audited — in the job
 *
 * A copy of the cash book is a bulk read of data that every screen in this
 * panel otherwise gates by policy, and unlike a screen it leaves the building.
 * Nothing else records that it happened: the rows are only read, so no model
 * event fires and LogsActivity sees nothing. The entry is written once the file
 * exists, because the row count is not known until then.
 */
class ExportTransactionsAction
{
    public static function excel(): Action
    {
        return static::base('exportExcel', 'xlsx')
            ->label('Unduh Excel')
            ->icon(Heroicon::OutlinedTableCells);
    }

    public static function pdf(): Action
    {
        return static::base('exportPdf', 'pdf')
            ->label('Unduh PDF')
            ->icon(Heroicon::OutlinedDocumentText);
    }

    /**
     * Everything the two formats agree on.
     */
    private static function base(string $name, string $format): Action
    {
        return Action::make($name)
            ->color('gray')
            // Checked server-side before the action mounts. The rule lives on
            // the resource next to the other authorization checks, so a future
            // second mounting point cannot get a weaker one.
            ->visible(fn (): bool => TransactionResource::canExport())
            ->action(function (Component&HasTable $livewire) use ($format): void {
                // Resolved now, while the filtered query still exists. The job
                // receives plain integers.
                $ids = $livewire->getFilteredTableQuery()
                    ->reorder()
                    ->pluck('transactions.id')
                    ->all();

                // Named at dispatch rather than at render, so the timestamp on
                // the file is when the book was asked for — which is the moment
                // the user can point at — rather than whenever a worker
                // happened to pick the job up.
                $fileName = static::fileName($format);

                ExportCashBook::dispatch(
                    $ids,
                    $format,
                    Auth::id(),
                    $fileName,
                    // Which subset is leaving the panel, carried through to the
                    // audit entry the job writes.
                    array_filter($livewire->tableFilters ?? []),
                );

                Notification::make()
                    ->title('Ekspor sedang diproses')
                    ->body(count($ids).' transaksi · '.strtoupper($format)
                        .'. Tautan unduhan akan muncul di lonceng notifikasi bila sudah siap.')
                    ->info()
                    ->send();
            });
    }

    private static function fileName(string $extension): string
    {
        return 'buku-kas-'.now()->format('Y-m-d-His').'.'.$extension;
    }
}
