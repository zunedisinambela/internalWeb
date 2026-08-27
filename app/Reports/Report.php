<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * What every downloadable report in this panel has to be able to say about
 * itself, so that one queued job can render any of them.
 *
 * ## Why this exists rather than a job per screen
 *
 * App\Jobs\ExportReport carries the parts that are hard and identical
 * everywhere: the uniqueness lock that survives a double click, the retention
 * that expires a file and its signed link together, the database notification
 * that has to arrive after the request has ended, the failure notice, and the
 * audit entry recording that a bulk copy of policy-gated data left the panel.
 * None of that has an opinion about which rows are being copied. This class is
 * the seam: a report knows its own columns, its own totals and its own
 * wording, and knows nothing about queues.
 *
 * ## One instance, one pass
 *
 * fold() accumulates as rows are walked, exactly the way App\Reports\CashBook
 * accumulates a running balance, and for the same reason it is written that
 * way there: laravel-excel streams the query through map() one row at a time,
 * so a total computed with a second aggregate query would be a second figure
 * able to disagree with the rows above it. Accumulating means the footer is
 * arithmetic over precisely the lines that were printed.
 *
 * The consequence is that an instance describes one walk. Iterating twice
 * doubles every total, which is why lines() resets first and why a renderer
 * picks streaming or eager and never mixes them.
 *
 * ## Ordering is imposed, never inherited
 *
 * query() reorder()s. The tables these reports are asked for from are sorted
 * however the reader last clicked, and a report whose row order depends on
 * that is a different document every time it is downloaded. It also matters
 * mechanically: FromQuery paginates to chunk, so a query with no deterministic
 * ORDER BY can repeat a row on one page boundary and drop another.
 */
abstract class Report
{
    private int $rows = 0;

    /** Bounds of what was actually folded, for the report header. */
    private ?Carbon $first = null;

    private ?Carbon $last = null;

    /**
     * Rebuild the report from the primary keys of a filtered selection.
     *
     * This is the constructor the queued job uses, and it is a static factory
     * rather than a plain constructor because a Report holds an Eloquent
     * Builder — which cannot travel on a queue, since a Builder holds a
     * Connection and a Connection holds a PDO handle.
     *
     * @param  array<int, int>  $ids
     */
    abstract public static function forIds(array $ids): static;

    /**
     * The rows to print: the caller's filtered selection, in report order, with
     * every relation both renderers put on the page eager-loaded.
     */
    abstract public function query(): Builder;

    /**
     * Fold one record into the report and hand back its printable line.
     *
     * Implementations call rowCounted() with the record's own date so that
     * period() describes the rows that were printed rather than the filter that
     * selected them.
     *
     * @return array<string, mixed>
     */
    abstract public function fold(Model $record): array;

    /**
     * The figures under the table, accumulated by fold().
     *
     * @return array<string, int>
     */
    abstract public function totals(): array;

    /** The spreadsheet renderer for this report. */
    abstract public function excel(): FromQuery;

    /** The Blade view the PDF renderer loads. */
    abstract public function view(): string;

    /**
     * Everything that view needs, evaluated eagerly.
     *
     * Eager rather than streamed because dompdf builds one HTML string: the
     * whole report is in memory whichever way the rows arrive.
     *
     * @return array<string, mixed>
     */
    abstract public function viewData(): array;

    /**
     * Human name of the document. Reaches the spreadsheet's sheet tab, the PDF
     * heading and the notification title, so the three cannot drift.
     */
    abstract public static function label(): string;

    /** What one row is, for "134 transaksi" in the notification. */
    abstract public static function unit(): string;

    /** Leading part of the file name — no timestamp, no extension. */
    abstract public static function slug(): string;

    /**
     * The activity_log event key. English, like every other event key in this
     * project — see Locale and timezone in CLAUDE.md.
     */
    abstract public static function event(): string;

    /**
     * A4 landscape suits every report here: they are all wide tables. Portrait
     * is available by overriding, but a report that needs it is usually a
     * report with too few columns to be worth a page.
     *
     * @return array{string, string}
     */
    public function paper(): array
    {
        return ['a4', 'landscape'];
    }

    /**
     * Every line at once, for the PDF renderer.
     *
     * Resets first so that the eager path and the streamed path cannot be mixed
     * by accident — a report folded twice would print doubled totals and look
     * entirely plausible doing it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(): Collection
    {
        $this->reset();

        return $this->query()
            ->get()
            ->map(fn (Model $record): array => $this->fold($record));
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    /**
     * The period the folded lines actually cover — read off the fold rather
     * than queried again, so it costs nothing and cannot disagree with the
     * lines above it. Null when nothing was folded, which is what an empty
     * report has to print instead of a date range.
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

    /**
     * Records that a row was printed, and when it happened.
     *
     * Rows arrive in report order, so the first one folded opens the period and
     * every one after it closes it. A report whose order is not chronological
     * passes null and simply has no period.
     */
    protected function rowCounted(?Carbon $occurredAt = null): void
    {
        $this->rows++;

        if ($occurredAt === null) {
            return;
        }

        $this->first ??= $occurredAt;
        $this->last = $occurredAt;
    }

    protected function reset(): void
    {
        $this->rows = 0;
        $this->first = null;
        $this->last = null;
    }
}
