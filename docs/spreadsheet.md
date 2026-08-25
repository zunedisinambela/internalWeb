# Spreadsheet

`maatwebsite/excel` v4.0.0, writing and reading through `phpoffice/phpspreadsheet` v5.

One export exists: `App\Exports\TransactionsExport`, asked for from the cash book list by
`ExportTransactionsAction::excel()` and rendered off the request by
`App\Jobs\ExportCashBook`. It renders `App\Reports\CashBook`, which the PDF report reads as
well — see Keuangan. Nothing imports yet.

```php
use Maatwebsite\Excel\Facades\Excel;

Excel::download(new LaporanExport, 'laporan.xlsx');   // or ->store('local', ...), ->raw(...)
```

A Filament action can return the `BinaryFileResponse` that `download()` produces — Livewire's
`SupportFileDownloads` intercepts it and turns it into a browser download. **The cash book does
not**: it renders in `App\Jobs\ExportCashBook` and stores through `Excel::store($export, $path,
'local')`, so the action returns nothing and the file is announced afterwards. Either way the
sheet is fully written by the time the call returns, so a count accumulated during the export —
`CashBook::rowCount()` — is final at that point and can be audited from there.

**`0` and `null` are the same value to `Worksheet::fromArray()`, and this bites twice.** It
skips any cell equal to its `$nullValue`, comparing loosely — and `0 != null` is `false` in
PHP, so **every zero in the file is silently dropped**. There is no error; the cell simply is
not created, and a zero reads back as an empty cell that means "not applicable".

It has to be closed in two separate places, because there are two paths onto the sheet:

| Path | Fix |
|------|-----|
| rows from `map()`, written by `Sheet::appendRows()` | implement `WithStrictNullComparison` on the export |
| anything written directly, e.g. a totals row in `AfterSheet` | pass `strictNullComparison: true` as `fromArray()`'s fourth argument |

`Sheet::hasStrictNullComparison()` also honours `excel.exports.strict_null_comparison`, which
is published here and left at its default `false`. Flipping that would close the first row of
the table for every export at once — and it is the wrong lever. Whether a `0` is data or
absence is a property of what a given export means, not a global preference, and a future
export that genuinely wants blank zeros would then have no way to say so. The concern says it
per export, where the reasoning lives.

`TransactionsExport` does both, and
`test_a_zero_prints_as_zero_while_a_blank_side_stays_empty` asserts the distinction survives:
`null` stays an empty cell, `0` prints a zero.

**Write numbers and dates as values, not as formatted strings.** A figure belongs in the cell
as an integer with a number format (`'"Rp" #,##0'`) beside it, and a timestamp as
`Shared\Date::dateTimeToExcel()` with `'dd/mm/yyyy hh:mm'`. Format codes are stored invariant
and Excel substitutes the viewer's own regional separators, so the same file reads
`Rp 1.500.000` in Indonesia without the writer having to guess where it will be opened. Format
the value and both properties are gone — no sums, no date sort, no locale.

**v4 was chosen over 3.1, and the version matters more than usual here.** Both lines were
released on the same day, both accept `illuminate ^13`, and the documentation site serves
`/3.1/` and `/4.x/` side by side — so a search result or a tutorial will almost always land on
3.1. The difference is underneath:

| | 3.1.70 | 4.0.0 |
|---|--------|-------|
| `php` | `^7.0 \|\| ^8.0` | `^8.3` — the project's own pin |
| `phpoffice/phpspreadsheet` | `^1.30.5` | `^5.8` |

`phpspreadsheet` 1.30.x declares `php >=7.4.0 <8.5.0`. Dev already runs 8.4, so 3.1 would have
installed against the top of its supported range and stopped resolving at 8.5 — a floor that
rises on its own. That line also took two CVE patches in four months (`CVE-2026-40296`,
`CVE-2026-34084`), and moving off it later means auditing every place the code touches a
PhpSpreadsheet object rather than Laravel-Excel's own API.

**Copying a 3.1 snippet mostly works, and fails on the signatures.** v4 added native types
across the public interfaces, so a concern written from the older docs raises a fatal:

```php
public function array()          // 3.1 — docblock only
public function array(): array   // 4.0 — enforced
```

`Exportable::queue()` returns `PendingDispatch|PendingBatch`, and `FromQuery` no longer accepts
a Scout builder — that moved to the new `FromScout`. `config/excel.php` has **no key changes**
between the two, so the published config is not a way to tell which version the surrounding
code was written for.

**`config/excel.php` is published and carries no deviations.** Two defaults are worth knowing
before anything here writes a second format:

- **The CSV defaults are American**: `delimiter => ','`, `use_bom => false`. Opened on an
  Indonesian Windows, whose list separator is `;`, such a file lands entirely in column A.
  `'excel_compatibility' => true` is the switch — `Writer\Csv` then forces a UTF-8 BOM, a
  leading `sep=;` line, `;` as the delimiter and CRLF endings, overriding the three keys above.
  It is off by default. Nothing here needs it until something actually exports CSV, and `xlsx`
  sidesteps the question completely by not being a text format.
- **`temporary_files.local_path` is `storage/framework/cache/laravel-excel`**, created at run
  time and covered by that directory's existing `.gitignore`. Exports larger than
  `chunk_size` (1000) stage there before being written.

**Anything queued needs a queue worker**, the same trap medialibrary conversions have under
Media: `QUEUE_CONNECTION=database`, so without one the job sits in the table, nothing is
written, and nothing is logged. `php artisan dev` runs `queue:listen`, so it only bites a
deploy. That now applies to the cash book export, which is queued *as a whole job* — and note
the distinction, because the two are not the same thing: `TransactionsExport` itself must never
implement `ShouldQueue`, since laravel-excel would then chunk it across jobs and restart the
running balance in each one. See Keuangan.

**`FromQuery` needs a deterministic `ORDER BY`.** It paginates the query to chunk it, so a sort
that ties — `occurred_at` on rows entered in the same minute, say — silently repeats and drops
records across page boundaries. Order by something unique, or add `id` as a tiebreak.

**An export is a read surface, so it is gated and audited.** A spreadsheet of records is a bulk
read of data every screen in the panel gates by policy, and unlike a screen it leaves the
building. The cash book is the worked example, split across two classes now that the render is
queued: authorization on the resource (`TransactionResource::canExport()`, checked in
`ExportTransactionsAction` before anything is dispatched), and an `activity()` entry under the
`monitoring` log name — written by `ExportCashBook` once the file exists, with the row count,
the format and the filters that were active. `TransactionExportTest` asserts both. Two things
that move with the audit call when a read goes onto the queue: the row count is not known until
the render finishes, and `causedBy()` has to be passed explicitly because a worker has no
authenticated user. Copy that shape for the next one — nothing else can record a read, since no
model event fires.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
