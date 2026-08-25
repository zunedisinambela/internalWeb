# Keuangan

The cash book. `/transactions` (`app/Filament/Resources/Transactions/`) records money in
and money out, each row optionally carrying photographs of its receipts. It, Listrik kost and
Oriflame are the three features that exist for their own sake; everything else in this panel is
there to keep them honest.

| Piece | Where |
|-------|-------|
| Model | `App\Models\Transaction` — the first `InteractsWithMedia` model here; `MeterReading` and `Sale` followed it |
| Direction | `App\Enums\TransactionType` — `income` / `expense` |
| Amounts | `App\Rules\WholeRupiah` — the only validation on the figure, and the grouped display |
| Totals | `Resources/Transactions/Widgets/TransactionOverview` |
| Receipts | media collection `Transaction::RECEIPTS`, private `local` disk |
| Ledger | `App\Reports\CashBook` — ordering, running balance and totals, shared by both exports |
| Export | `Resources/Transactions/Actions/ExportTransactionsAction` — `excel()` and `pdf()`, both dispatch |
| Render | `App\Jobs\ExportCashBook` — off the request; writes the file, audits it, announces it |

**Amounts are whole rupiah in an `unsignedBigInteger`, never a decimal.** SQLite has no real
`DECIMAL` type: `decimal(15,2)` becomes NUMERIC affinity and comes back through PDO as a float,
which cannot represent `0.1` exactly, so totals drift once the numbers get large. IDR is not
spent in fractions, so integer rupiah removes the problem rather than managing it. Two
consequences that are easy to undo by accident:

- `App\Rules\WholeRupiah` on the form field is the only thing catching a fractional amount.
  SQLite is loosely typed: a `1500.75` reaching an INTEGER-affinity column is stored as a real,
  raises nothing, and comes back as `1500` once the model's `integer` cast has run.
  `test_a_fractional_amount_reaching_the_model_is_lost_quietly` pins that behaviour so the
  reason for the guard survives a refactor of the form.
- The table and infolist format with `number_format($state, 0, ',', '.')` rather than
  `->money('IDR')`. `money()` would render the figure correctly — it does not divide by 100
  unless told to — but it cannot prefix the `+` / `−` that says which direction the money went,
  and that sign is the point of the column.

`unsigned` is deliberate too: direction lives in `type`, so a negative expense would make the
same row readable two ways.

**The field shows `1.500.000` and stores `1500000`.** Three pieces do that, and removing any one
of them breaks it without an error:

| Piece | Does |
|-------|------|
| `->live(onBlur: true)` with `afterStateUpdated()` | regroups what was typed, once the field loses focus |
| `->formatStateUsing()` | groups the stored integer when an existing row is opened for editing |
| `->dehydrateStateUsing()` | strips the separators on the way back into the column |

Four things that look like the answer and are not:

- **`->numeric()` and `->integer()`.** Both make `TextInput::getType()` return `number`, and a
  number input will not display a thousands separator — the browser rejects `1.500.000` and
  leaves the field blank. Dropping them also drops the `integer`, `min` and `max` rules they
  registered, which is the whole reason `App\Rules\WholeRupiah` exists.
- **`->mask(RawJs::make('$money(…)'))`.** Filament v5 bundles no Alpine mask plugin, so the
  `x-mask` attribute is rendered and nothing implements it. See Filament conventions.
- **`->stripCharacters('.')`.** Filament applies it in `mutateStateForValidation()` and *not* in
  `mutateDehydratedState()`, so on its own it lets `1500.75` validate as `150075` and then
  stores that.
- **Stripping dots in `afterStateUpdated()`.** A dot separates thousands in `1.500.000` and
  decimals in `1500.75`. Only what `WholeRupiah::isUnambiguous()` accepts is regrouped; anything
  else is left exactly as typed so the rule can refuse it. Regrouping it instead would turn
  Rp 1.500,75 into Rp 150.075 — an error nothing downstream can catch, because the result is a
  perfectly valid integer.

**What counts as ambiguous is narrower than "badly grouped", and deliberately so.** The rule
asks one question: *could this be a decimal?* Only `WholeRupiah::DECIMAL_TAIL` — a dot with one
or two digits at the very end — could be. Everything else that is digits-and-dots is regrouped
however untidy it arrived.

That matters because the field reformats itself. Typing one more digit onto an
already-grouped `10.000` gives `10.0000`, which is not valid grouping but cannot mean anything
except `100.000`. An earlier version demanded tidy groups of three and put a validation error
under the field while the user was still typing — the feature fighting its own output.
`test_a_digit_appended_to_a_grouped_amount_is_regrouped_not_refused` is what keeps that from
coming back, and `test_an_ambiguous_amount_is_not_quietly_regrouped` guards the other edge.
`WholeRupiahTest` holds the full accepted/refused table.

**`occurred_at` is not `created_at`.** It defaults to `now()` when the form opens — which is
what "waktu saat dibuat" asks for — but stays editable, because a receipt found a week later
has to be datable to when the money actually moved. `now()` is already WIB (see Locale and
timezone), so nothing is converted anywhere in this feature.

**Enum values are English, labels are Indonesian.** `income` / `expense` are what land in the
column, get filtered on and get asserted in tests; `TransactionType::getLabel()` is the only
user-facing text. Same rule as the activity log `event` keys, and for the same reason — a
reworded translation must not become a data migration.

**Uploads go through medialibrary, not a plain `FileUpload`.** There is no path column on
`transactions` — attachments are rows in the `media` table keyed by morph, which is why "more
than one photo" costs no schema change and no migration. Three components bind to the
`receipts` collection *by name*: `SpatieMediaLibraryFileUpload` on the form,
`SpatieMediaLibraryImageColumn` on the table, `SpatieMediaLibraryImageEntry` on the infolist.
All three come from `filament/spatie-laravel-media-library-plugin`, not from Filament itself.
A name that does not match a registered collection is not an error — see Media for where the
file ends up when it happens.

**Receipts are on the private disk.** `registerMediaCollections()` pins `->useDisk('local')`.
A receipt photograph carries amounts, account numbers and addresses, so publishing it by URL on
the `public` disk would be a read surface that sidesteps every policy the rest of the panel
enforces. The mechanics of how the private disk is served, and the limits of that protection,
are under Media. All three Filament components that render a receipt set
`->visibility('private')`; drop it from any one of them and that surface silently renders
broken images.

The `thumb` conversion is `->nonQueued()`, and does double duty: it survives a deploy with no
queue worker, and being re-encoded it drops almost all of the EXIF the phone wrote into the
original. Lists and infolists show the conversion, so the original — GPS coordinates included —
is only ever reached by a deliberate signed request.

**Auditing is split three ways**, because no single mechanism can see all of it:

| Change | Recorded by |
|--------|-------------|
| `type`, `amount`, `description`, `occurred_at` | `LogsActivity`, log name `transaction` |
| a receipt removed | `AppServiceProvider::registerMediaDeletionLogging()`, event `receipt_deleted` |
| the book downloaded, as Excel or PDF | `ExportTransactionsAction`, log name `monitoring`, event `transactions_exported`, `format` property |
| a receipt attached or replaced | **nothing** |

Deleting a whole transaction writes its own `deleted` entry *and* one `receipt_deleted` per
attached file. That duplication is wanted: a receipt removed on its own and a receipt that went
down with its row are different events, and the log should not have to infer which happened.
It depends on two unrelated mechanisms lining up — medialibrary removing its files from the
`deleting` event, and the `Media::deleted` listener firing once per row — so
`test_deleting_a_transaction_audits_the_row_and_each_receipt` asserts the counts rather than
leaving it to be noticed later.

**The exports are not a mirror of the screen, and cannot be.** `Unduh` on the list page offers
the book as `.xlsx` or `.pdf`, and both write it as a two-column ledger — `Pemasukan`,
`Pengeluaran`, running `Saldo` — where the table shows one signed amount. The table can prefix
`+` or `−` because it is rendering text; a spreadsheet cell holding `+ Rp 1.500.000` is a
string, and a column of strings cannot be summed, pivoted or charted, which is most of what a
spreadsheet is for. So the direction moves into the column layout. The PDF follows the same
shape rather than the screen's, because two files downloaded seconds apart disagreeing about
the layout of the same book would be worse than either choice.

**`App\Reports\CashBook` is the single source of what the book says** — the ordering, the
running balance, the totals and the period. Both renderers read it and neither is allowed its
own opinion; a figure that differed between the two files would be very hard to notice and
impossible to explain. `TransactionsExport` decides only how those figures reach a spreadsheet
cell, and `pdf.buku-kas` only how they reach a page.

Consequences worth keeping:

- **The exports impose their own sort and inherit only the filters.** They read
  `getFilteredTableQuery()`, not `getFilteredSortedTableQuery()`. `Saldo` is a running total and
  only means anything read oldest-first, while the table defaults to `occurred_at desc`. `id` is
  the tiebreak: `FromQuery` paginates to chunk, so rows sharing a timestamp would otherwise
  straddle a page boundary in an unstable order and one would repeat while another vanished.
- **One `CashBook` instance describes one pass.** `fold()` advances the balance on every call,
  so iterating twice doubles every total. `lines()` is the eager entry point the PDF uses —
  dompdf builds one HTML string, so it holds the book in memory either way — and it resets
  first so the two cannot be mixed by accident.
- **Do not add `ShouldQueue` to `TransactionsExport`.** The balance accumulates as `map()` walks
  the rows. Queued chunks run in separate jobs with their own instance, so each chunk would
  restart the balance from zero — and the file would still look entirely plausible. Moving the
  running total into a SQL window function is the prerequisite for queueing it *that* way.
  The render is nonetheless off the request — see below — because the whole of it is wrapped
  in one job rather than chunked across several.
- **`WithStrictNullComparison` is load-bearing, not decoration.** See Spreadsheet.

**Both formats are rendered on the queue, and the action returns nothing.**
`App\Jobs\ExportCashBook` does the work; `ExportTransactionsAction` only resolves the filtered
set, dispatches, and says so. Five things follow from that, and each is a decision:

- **The job carries ids, not the query.** An Eloquent builder holds a `Connection`, which holds
  a PDO handle, and PDO refuses to serialize — dispatching one dies with
  *"Serialization of 'PDO' is not allowed"*. Re-applying the filters inside the job is not
  available either: they live on a Livewire component that no longer exists. So
  `getFilteredTableQuery()` is resolved to primary keys at dispatch. The payload grows with the
  book, and a row deleted between dispatch and render is simply absent from the file — the
  honest outcome, the alternative being a file claiming a row that no longer exists.
- **One job, one `CashBook`, one pass.** That is exactly the arrangement the synchronous
  download had, which is why the running balance survives the move. Adding `ShouldQueue` to
  `TransactionsExport` would break it again from the inside.
- **Sending that notification is itself a queued job.** Filament's
  `Notifications\DatabaseNotification` implements `ShouldQueue`, so `sendToDatabase()` hands off
  to `SendQueuedNotifications` rather than inserting the row inline. It works, and it is a
  seam: that job has its own retries and its own failure, outside
  `ExportCashBook::failed()`. If it dies, the file exists, the audit entry exists, and nobody
  is told. `$user->notifyNow(...)` is the one-line alternative, and it
  trades that for making a failed insert fail the whole export.
- **The finished file reaches the user as a database notification**, so the panel now enables
  `->databaseNotifications()` and the `notifications` table exists. A flash message could not
  work: the request that asked for the file has ended before the file exists. The notification
  carries a signed `temporaryUrl()` onto the private `local` disk — the same protection a
  receipt gets, with the same limit (see Media).
- **The file expires with its link.** `ExportCashBook::RETENTION_HOURS` sets both the signature
  expiry and the cutoff `App\Console\Commands\PruneExports` deletes on, scheduled hourly. One
  constant for both on purpose: split them and you get either a live link to a deleted file, or
  a copy of the book nothing will ever remove. It is deliberately *not* a setting on
  `/monitoring`, unlike the monitoring retention, for that reason.
- **One render per request, not one per click.** The job is `ShouldBeUnique`, keyed on
  `userId:format:md5(sorted ids)` — who asked, in what format, over which rows. Keying on the
  user and format alone would also swallow the legitimate case: filter the screen differently,
  click again, and that export is silently discarded while the screen says it is being
  processed. Including the row set means only a genuine repeat is refused, and *because* the key
  is the request, the "sedang diproses" flash stays true for the dropped click too — which is
  what makes it safe to drop it without telling anyone. Two details that fail silently if
  changed: the ids are **sorted before hashing**, since the action calls `reorder()` and the
  same set can come back in a different order, and `$uniqueFor = 900` is longer than
  `$timeout = 600`, since a lock that expires mid-render lets the duplicate through and one that
  never expires (the default `0`) wedges that row set forever if a worker is killed.

  **The lock lives in the cache, so `CACHE_STORE` decides whether the guard works at all.**
  `database` is what is set, and `file` or `redis` are equally fine — all three are shared
  between the web process that dispatches and the worker that releases. A **per-process** store
  is not: with `array`, every click is a fresh store that acquires cleanly, so the guard
  silently does nothing while every test still passes (`phpunit.xml` pins `CACHE_STORE=array`,
  and both dispatches in a test happen inside one process). That asymmetry is the trap — the
  suite cannot see it.
- **`$tries = 1`.** A retry would write a second file and a second `transactions_exported`
  entry for one act, and rendering is deterministic — a failure is a bad query, a missing font
  or an exhausted memory limit, none of which improve on a second attempt. `failed()` notifies
  the user with a deliberately opaque message and puts the exception in the log; a notification
  body is `sanitizeHtml()`ed rather than escaped (see Gotchas), and exception text carries SQL
  and absolute paths.

**The audit entry is written by the job, not the action**, once the file exists — `rowCount()`
is accumulated by the fold and is not final until then. `causedBy()` is explicit there, because
a queue worker has no authenticated user and the entry would otherwise name nobody.

**No worker means no file and no error.** `QUEUE_CONNECTION=database`, so a deploy without
`queue:work` leaves the job in the table, the notification never arrives, and nothing is logged
— the same trap medialibrary conversions have under Media. `php artisan dev` runs
`queue:listen`, so this only bites a deploy.

Who may download either is `TransactionResource::canExport()`, which defers to `canViewAny()`:
the files carry no column the table does not already show the same caller, so a separate gate
would restrict the format rather than the data. What changes is that the data leaves the panel,
which is why the download is audited instead — nothing else could record it, since the rows are
only read and no model event fires. Both formats write **one** event with a `format` property,
not an event each: taking a copy of the book is a single act, and filtering the log for it
should not mean remembering two keys.

**Past entries are rewritable, and that is an open question rather than a decision.** Anyone
holding `Update:Transaction` can change the amount on a row from months ago, and anyone holding
`Delete:Transaction` can remove it; only `activity_log` records that it happened. That is the
normal Shield behaviour and it is deliberate only in the sense that nothing overrode it — the
resource leaves `canEdit()` and `canDelete()` unimplemented so the policy decides, the way the
Filament conventions section says to.

The alternative is an append-only book: rows lock after some period and corrections are entered
as reversing transactions. It is more honest to audit and more annoying to use, and it is a
question about how this organisation keeps its books rather than a technical one. If it is ever
answered, the rules go on `TransactionResource` next to the other record-level checks — not on
the buttons, or the bulk path stays open. `UserResource::canDelete()` is the shape to copy.

**The author is stamped server-side.** `CreateTransaction::mutateFormDataBeforeCreate()` sets
`user_id` from the session rather than exposing a select, so a crafted request cannot attribute
an entry to someone else. `Transaction::booted()` does the same for rows created outside a form
and leaves it null when nobody is signed in — an unattributed row is honest, a guessed one is
not. `user_id` is `nullOnDelete`, matching the monitoring tables: removing an account must not
erase the financial record it left behind.

**The totals widget lives under the resource, not in `app/Filament/Widgets`.** The panel
provider calls `discoverWidgets()` on that directory and everything it finds lands on the
dashboard — which is deliberately limited to `AccountWidget`, and which anyone holding any role
can open. These figures are gated by the transaction policy, so the file stays beside the
resource that checks it.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
