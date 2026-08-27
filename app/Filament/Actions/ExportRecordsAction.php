<?php

namespace App\Filament\Actions;

use App\Jobs\ExportReport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Asks for a list screen, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Both formats are here rather than in a class each because everything that
 * matters about them is shared: who may do it, which rows they get, and the job
 * that renders them. Only the file extension differs. Two copies of an
 * authorization rule is one copy too many — and with four screens exporting,
 * that argument scales from two copies to eight.
 *
 * ## It queues, it does not download
 *
 * The render happens in an App\Jobs\ExportReport subclass, off the request. So
 * these actions return nothing at all — no BinaryFileResponse, no
 * StreamedResponse — and the finished file reaches the user as a database
 * notification carrying a signed link. The queue connection is `database`, so a
 * deploy with no worker leaves the job sitting in the table and the
 * notification never arrives; the same trap medialibrary conversions have.
 * `php artisan dev` runs queue:listen.
 *
 * ## It exports the filtered set, not the page
 *
 * getFilteredTableQuery() gives the query with the caller's filters applied and
 * no pagination, so a filtered screen produces a filtered file. Its sibling
 * getFilteredSortedTableQuery() would also carry the table's sort, which is
 * exactly what must not happen — see App\Reports\Report for why a report
 * imposes its own order.
 *
 * The rows are resolved to ids here rather than passing the query itself: a
 * builder cannot be serialized onto a queue, since it holds a PDO handle. See
 * the job for what that costs.
 *
 * The key is qualified — `sales.id`, not `id`. A table with a join or a
 * withSum subquery can put more than one `id` in scope, and an unqualified
 * pluck then fails with an ambiguous column error at the moment somebody clicks
 * the button rather than at the moment the join was added.
 *
 * ## It is audited — in the job
 *
 * A copy of a list screen is a bulk read of data that every screen in this
 * panel otherwise gates by policy, and unlike a screen it leaves the building.
 * Nothing else records that it happened: the rows are only read, so no model
 * event fires and LogsActivity sees nothing. The entry is written once the file
 * exists, because the row count is not known until then.
 */
abstract class ExportRecordsAction
{
    /** @return class-string<ExportReport> */
    abstract protected static function job(): string;

    /**
     * Whether the signed-in user may take a copy.
     *
     * Delegated to the resource rather than written here, so that a second
     * mounting point cannot get a weaker rule than this one.
     */
    abstract protected static function can(): bool;

    /**
     * The primary key, qualified by its table.
     */
    abstract protected static function qualifiedKey(): string;

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
     * The two formats as one header button.
     *
     * Grouped rather than two buttons: it is one act — take a copy of this
     * screen — and the file format is a detail of it. List pages mount this
     * left of the create button, since it reads what the filters currently
     * show.
     */
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            static::excel(),
            static::pdf(),
        ])
            ->label('Unduh')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->button()
            ->color('gray');
    }

    /**
     * Everything the two formats agree on.
     */
    protected static function base(string $name, string $format): Action
    {
        $job = static::job();

        return Action::make($name)
            ->color('gray')
            // Checked server-side before the action mounts.
            ->visible(fn (): bool => static::can())
            ->action(function (Component&HasTable $livewire) use ($job, $format): void {
                // Resolved now, while the filtered query still exists. The job
                // receives plain integers.
                $ids = $livewire->getFilteredTableQuery()
                    ->reorder()
                    ->pluck(static::qualifiedKey())
                    ->all();

                $job::dispatch(
                    $ids,
                    $format,
                    Auth::id(),
                    $job::fileName($format),
                    // Which subset is leaving the panel, carried through to the
                    // audit entry the job writes.
                    array_filter($livewire->tableFilters ?? []),
                );

                Notification::make()
                    ->title('Ekspor sedang diproses')
                    ->body(count($ids).' '.$job::report()::unit().' · '.strtoupper($format)
                        .'. Tautan unduhan akan muncul di lonceng notifikasi bila sudah siap.')
                    ->info()
                    ->send();
            });
    }
}
