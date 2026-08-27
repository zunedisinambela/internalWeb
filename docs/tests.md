# Tests

`tests/Feature` covers the security-relevant behaviour; run the suite before changing any of it.
334 tests at the last count.

| File | Locks in |
|------|----------|
| `PanelAccessTest` | roleless/super-admin/guest access, removing the last role locks out |
| `LoginTest` | both identifiers reach the same account, the username is matched case-insensitively, a roleless user is refused by either, the refusal lands on a field that exists, an '@' cannot enter a username, usernames store lowercase, changes audited |
| `UserActivityLoggingTest` | what is logged, what is never logged, causer, role grant/revoke |
| `ActivityLogPanelTest` | list and view render, no create/edit, deletes go to the file log |
| `LogViewerAccessTest` | guests and roleless users blocked from the page *and* the API |
| `UserResourceTest` | password hashing, blank-password edits, confirmation, self-delete refusal |
| `UserMonitoringTest` | package routes stay gone, middleware coverage on both stacks — the panel's and the `web` group's `/log-viewer` — delete auditing |
| `MonitoringRetentionTest` | retention saves, blank means forever, prune scope and summary |
| `TwoFactorAuthenticationTest` | password alone is refused, valid code passes, secret never leaks, three audit events, admin reset |
| `TransactionResourceTest` | policy gating, integer rupiah and what a fractional amount costs, grouped input round-trips and an ambiguous one is left alone, `occurred_at` default, receipts stay private and unsigned reads are refused, each receipt is its own link and the lightbox wrapper is marked, receipt / cascade / bulk delete auditing |
| `TransactionExportTest` | who may download the book, the two-column ledger and its running balance, chronological order regardless of the table sort, filters carry over, amounts and dates are values rather than text, `0` prints while a blank side stays empty, **both formats queue rather than download** and the job carries the filtered ids and a name stamped at dispatch, the rendered file lands on the private disk and is announced by a database notification, a second click on the same screen queues nothing while a different row set or format still does, the same rows in a different order are one request, an expired file is pruned while a fresh one is kept, both formats audit under one event, the PDF escapes user text and signs a negative balance readably, an empty book still renders |
| `MeterReadingResourceTest` | policy gating, usage derived from the stored figures while the bill is stored as typed, **a later reading does not change an earlier bill** and editing one leaves the recorded amount alone, the amount field is on screen and required, **it is deliberately not prefilled from the previous reading**, a grouped amount is stored as a whole integer, an amount far beyond a plausible one is refused, a zero bill is accepted on an unchanged meter, a wrong amount is corrected on the field itself, the form prefills both ends of the previous reading and takes them from the latest *period* rather than the last row written, a meter with no history opens at zero and at now, a closing figure below the opening one is refused, a closing moment before the opening one is refused while an equal one is accepted, author stamped, the create button is available on an empty log, a photo belongs to the end it was uploaded against, photos stay private and unsigned reads are refused, nothing outside the allowlist is logged, photo / cascade / bulk delete auditing |
| `SaleResourceTest` | policy gating, the margin derived from the three stored figures, **the item count does not reprice the order**, the free item derived from the count at each boundary and carrying no money, a sale recorded without a quantity counts as one, the quantity defaults to one on the form and a correction to it is audited, **ongkir is a cost to the consultant rather than a charge to the customer**, grouped input round-trips into integer columns, a zero ongkir is accepted while a zero price is still refused, a marketing price above the catalogue price is refused while an equal one is accepted, a negative margin reported rather than clamped, author stamped, the date defaults to now and ongkir to zero, the create button waits for a customer, price corrections audited, nothing outside the allowlist is logged, one `deleted` entry per sale, attachments uploaded on the create form reach their collections, attachments land on the private disk and an unsigned read is refused, a file belongs to the collection it was uploaded against, a collection holds more than one file, every screen renders with one attached, each attachment is its own link on the view screen and the two collections are separate lightbox groups, the list carries the class its table floor is keyed on, attachment / cascade delete auditing |
| `FreeItemRedemptionTest` | a handover draws down what is owed without touching what was earned, two free items collected in one handover, nothing collected leaves the whole bonus owed, a bonus collected then unearned reads as a **negative** rather than being clamped, the customer screen carries the three figures and registers the handover table, the form refuses to hand over more than is owed, a second handover measured against what the first one left (the `fresh()` pin), the create button gone once every bonus is collected, an existing handover editable without being refused by itself, the entered date and resi stored, a handover without a resi accepted, the resi photo on the private disk and reaching its collection from the create form, its own log name, the resi photo's removal audited, the author stamped, the list showing what is still owed, a customer with a handover undeletable, gating on the customer's permissions |
| `CustomerResourceTest` | policy gating, totals summed across every sale with ongkir out of what the customer paid, a customer with no sales totals zero, the free item counted across orders rather than per order (both readings asserted in one test), every whole threshold counted with the distance to the next named, a customer with no sales having earned nothing, the customer bonus leaving both money totals alone, the list showing the summed item count and the bonus it earned, a customer with sales cannot be deleted from the resource *or* the database, deactivation keeps their sales, phone and address changes audited, a long address survives the round trip, an address is searchable while its column is toggled off, bulk delete audited per row |
| `ReportExportTest` | the three screens that gained an export, and the evidence column the cash book PDF gained with them: that each screen dispatches **its own** job so one screen's double-click guard cannot swallow another's, that each report's totals are its own arithmetic — a derived sales margin, a customer aggregate that arrives `null` rather than `0`, two meter columns that are never multiplied — that the generated workbooks put their totals under the right columns, that every one of the four PDF views escapes user text, that the Bukti column embeds the `thumb` and never the original, that a fourth receipt is counted as `+n` rather than dropped, that a missing file prints a dash instead of nothing, and that the header separator renders as markup rather than printing as `&middot;` |
| `EditRedirectTest` | that Simpan lands on the list for the four screens worked in daily, that the write happens before the redirect rather than being traded for it, and — the reason the file exists — that a resource **without** the trait still stays on its form, so swapping it for the panel-wide `resourceEditPageRedirect()` cannot pass |
| `PanelCacheTest` | the balance badge answers a second request without a query, a save and a delete each invalidate it, a write reaches the overview totals as well as the badge, **a null value is cached rather than re-queried** and a forgotten key is resolved again |
| `PageViewsOnlyTest` (Unit) | which requests count as a visit |
| `WholeRupiahTest` (Unit) | which amounts are whole rupiah, that untidy grouping is accepted, and that `1500.75` is refused rather than regrouped |

**`phpunit.xml` raises `memory_limit` to 512M**, and that is not decoration. The whole suite runs
in one process, and `TransactionExportTest` / `ReportExportTest` build real `xlsx` files through phpspreadsheet and
zipstream — by far the heaviest thing here. Past roughly two hundred tests it began exhausting
PHP's 128M default, and the failure is a fatal error *inside zipstream* with no assertion
attached: it reads as a broken export rather than as a memory ceiling. Lower it back and the
next test file added rediscovers that the hard way.

**A factory that randomises one end of a period will fail the tests that pin the other.**
`MeterReadingFactory` draws its opening moment anywhere in a five-month window, and most tests
here override `end_read_at` alone — so about one run in twenty drew a start *after* the pinned
end and failed on *"Waktu pembacaan akhir tidak boleh mendahului waktu pembacaan awal"*, a
message about the form rather than about the test. Its `configure()` now drags the start back
whenever a pinned end lands before it, in `afterMaking()` so nothing wrong is ever written. The
general shape: when a factory produces two values that must stay ordered, it has to keep them
ordered after a test has overridden one of them, not only in `definition()`.

**A relation manager is lazy, so a page test cannot see its rows.** The first response carries a
placeholder and the rows arrive on a second Livewire request, which is why
`FreeItemRedemptionTest` asserts the customer screen's own figures with `get()` and drives the
table itself with
`Livewire::test($manager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])`.
Its actions are also refused unless `isReadOnly()` is overridden on the manager — on a
`ViewRecord` page the default hides create, edit and delete, and the failure message is
*"Failed asserting that an action with name [create] is visible"*, which reads exactly like a
missing permission.

**A faked disk proves the file landed, not that its link works.** The export tests assert
`Storage::disk('local')->assertExists('exports/…')`; the signed URL the notification carries is
only exercised against the real disk, for the reason in the next paragraph.

**`Storage::fake()` cannot test signed URLs.** It replaces the disk's temporary-URL builder with
a stub returning `URL::to($path.'?expiration=…')` — no signature, no `/storage` prefix — so a
faked disk always answers 404 for a link that would work in production. The split in
`TransactionResourceTest` is the way around it: the refusal case runs on a faked disk, because
`ServeFile` checks the signature *before* it looks for the file; the accepting case writes a
throwaway file to the real `local` disk and cleans it up in a `finally`.

**A PDF is asserted twice, in two different places.** dompdf compresses object streams, so the
rendered text is not greppable in the output — assertions on the bytes can only reach as far as
the `%PDF-` magic. What the document *says* is asserted against the rendered **HTML** instead
(`view(...)->render()`), which is where escaping and number formatting are decided, and against
`CashBook`, which is the source both renderers read. `TransactionExportTest` does all three, and
`ReportExportTest` repeats the shape for the three reports added after it.

Whatever renders a PDF, verify it by eye once as well: `show_warnings` is `false`, so a
mis-specified font or a missing asset produces a valid document with the problem in it and
nothing in the log. `pdftotext -layout` is enough to check the page structure, the totals and
the page numbering without opening a viewer.

**Since the reports print photographs, `pdftotext` is no longer sufficient on its own** — an
image dompdf silently declined to load leaves no trace in the text layer.
`pdftoppm -png -r 90 -f 1 -l 1 file.pdf out` renders the first page and is what caught the one
bug the whole suite missed: an `&middot;` assembled in PHP and printed through `{{ }}`, which
looks exactly like correctly-escaped user text in every HTML assertion.
`ReportExportTest::test_the_header_separator_is_rendered_and_not_printed_as_an_entity` exists
because of that, and it is the assertion to copy for any separator a report builds in PHP.

**A spreadsheet is asserted by reading it back, not by grepping it.** An `xlsx` is a zip, so
its cell values are not in the bytes. `TransactionExportTest` renders with
`Excel::raw($export, Excel::XLSX)`, writes the result to a temp file — phpspreadsheet loads
from a path, not a string — and inspects cells through `IOFactory::load()`. That is what makes
the interesting assertions possible at all: `getValue()` proves an amount is an integer rather
than a formatted string, and `getStyle(...)->getNumberFormat()->getFormatCode()` proves the
rupiah is a display format rather than part of the data.

**The delivery is a separate concern, and `assertFileDownloaded()` no longer applies** — the
action queues the render and returns nothing. Two halves, tested apart:

- **What was asked for.** `Bus::fake()`, then `Bus::assertDispatched(ExportCashBook::class, …)`
  reading the job's public readonly `ids`, `format` and `fileName`. Freeze the clock with
  `Carbon::setTestNow()` first, since the name carries a timestamp stamped at dispatch.
- **Whether it was asked for twice.** `Bus::fake()` does *not* bypass the uniqueness lock:
  `PendingDispatch::shouldDispatch()` acquires it before the dispatcher is reached at all, so
  `Bus::assertDispatchedTimes(…, 1)` is a genuine assertion about the guard rather than a count
  of calls. The lock is per-process cache state, which is why these tests work at all under
  `CACHE_STORE=array` — and why they cannot catch a deploy that sets the same value. See
  Keuangan.
- **What came out.** `QUEUE_CONNECTION` is `sync` in `phpunit.xml`, so without `Bus::fake()` the
  job simply runs inside `callAction()` — which is what lets the audit assertions stay as they
  were. Pair that with `Storage::fake('local')` or the suite writes real spreadsheets into
  `storage/app/private/exports` and leaves them there.

`Excel::fake()` with `assertDownloaded()` is not the route here: it asserts an export was
dispatched rather than what ended up in the cells, and the export is no longer downloaded.

`Tests\TestCase` provides `userWithRole()`, `superAdmin()` and `seedRoles()`. Roles come from
`ShieldSeeder` so tests exercise the same data a deploy produces, and the permission cache is
cleared afterwards — without that, a role created mid-test stays invisible to `Gate` checks.

`userWithRole()` derives `username` from whatever address it was given rather than from the role,
because two users in one test are told apart by their email and a role-keyed username would
collide between a `superAdmin()` and a second one. Any test that writes a `User` row by hand has
to supply the column: it is `NOT NULL`, so `User::create()` without it fails on the constraint
rather than on an assertion. `UserFactory` fills it with a faker `userName()` with dots replaced,
since the form's `alphaDash` rule is what the value has to look like.

The login form's field is `login`, not `email` — `fillForm(['login' => …])` — and the page under
test is `App\Filament\Auth\Login`, not Filament's. Testing against the base class silently
fills nothing, and the assertion that fails is `assertAuthenticatedAs`, which names neither.

`TransactionFactory` has `income()` and `expense()` states, both taking an explicit amount, so a
test that asserts on a total never depends on a random one. `user_id` defaults to null: the
model's `creating()` hook fills it from the session, and a factory that guessed an author would
hide that.

Filament actions are tested through Livewire:
`Livewire::test(ListVisits::class)->callAction(TestAction::make('delete')->table($record))`,
and `->selectTableRecords([...])` then `TestAction::make('delete')->table()->bulk()` for the
bulk path. `callTableAction()` also exists; `TestAction` is the v5 form.

The multi-factor challenge cannot be driven with `fillForm()`. It shares the login form's
`data` state path and nests each provider under its own id, so the code has to be set directly:
`->set('data.multiFactor.app.code', $code)`. Generate a valid one with
`app(AppAuthentication::class)->getCurrentCode($user)`.

The log viewer's API is tested separately from its UI because it has its own middleware stack
(`api_middleware` in `config/log-viewer.php`) and returns raw log contents.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
