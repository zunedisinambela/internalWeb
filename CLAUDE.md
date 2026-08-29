# internalWeb

Internal admin web app. Laravel 13 + Filament v5 panel. PHP 8.4+.

The pin was `^8.3` until it was corrected: `composer.lock` has all along held
eighteen packages requiring `>= 8.4.1` — Symfony 8 and `spatie/laravel-activitylog ^8.4`
among them — because Composer resolves against the PHP actually running, not against
the constraint in `composer.json`. That gap is invisible locally and fatal in a
container: on a `php:8.3` image the constraint says the image is fine and
`composer install` then refuses every package that disagrees, naming a package rather
than the PHP version.

## Commands

```bash
composer setup       # install, .env, key:generate, migrate, npm install + build
composer dev         # -> php artisan dev (server, queue, logs, vite in one process)
composer test        # config:clear then php artisan test (PHPUnit 12, not Pest)
vendor/bin/pint      # format; no pint.json, so Laravel preset defaults apply
php artisan migrate:fresh --seed   # rebuild sqlite + seed admin user
php artisan schedule:work          # NOT part of `composer dev` — see Monitoring
php artisan monitoring:prune       # apply retention now instead of waiting for 03:00
php artisan exports:prune          # delete finished report exports past their link expiry
php artisan storage:link           # NOT part of `composer setup` — see Media
php artisan cache:clear            # last resort for a stuck navigation badge — see Gotchas
```

## Stack notes

- **Laravel 13**, framework `^13.17`. Several APIs differ from Laravel 10/11 docs — check
  `vendor/laravel/framework` before trusting an older recipe.
- **Filament v5** (`^5.0`) mounted at the **root path** — `->path('')`, so `/` is the
  dashboard, `/login` the sign-in screen and `/transactions` the cash book, with no `/admin`
  segment anywhere. Panel config lives entirely in
  `app/Providers/Filament/AdminPanelProvider.php`, registered via `bootstrap/providers.php`.
  The panel id stays `admin`, so route names are still `filament.admin.*` — the id names the
  panel, not the URL, and renaming it would break every `route()` call and Shield's panel
  flag while changing no path. Three consequences of the empty path:
  - `routes/web.php` must define **no** route for `/`, or it races the dashboard for the same
    path. The file is deliberately empty.
  - A new resource slug now shares one namespace with `/log-viewer`, `/storage/{path}`,
    `/up`, `/login` and `/logout`. Check `php artisan route:list` when adding one instead of
    assuming a prefix keeps them apart — a slug of `storage` or `login` would collide in
    silence.
  - Nothing redirects the old `/admin/...` URLs; they 404. Adding
    `Route::redirect('/admin/{any?}', '/')` would bring the segment back for bookmarks, and
    was left out on purpose.
- **spatie/laravel-activitylog v5** — audit trail in the `activity_log` table, browsable at
  `/activities`.
- **opcodesio/log-viewer v3** — log file browser at `/log-viewer`, outside the Filament panel.
- **bezhansalleh/filament-shield v4** on **spatie/laravel-permission v8** — roles and
  permissions, managed at `/shield/roles`.
- **binafy/laravel-user-monitoring v1** — page views and sign-in history, at `/visits`
  and `/authentications`. Installed as a data collector only; its own routes and Blade
  dashboards are disabled. See Monitoring.
- **spatie/laravel-medialibrary v11** — file attachments on Eloquent models, `media` table.
  Four models, six collections: `App\Models\Transaction` for receipt images,
  `App\Models\MeterReading` for meter photographs under a collection per meter figure,
  `App\Models\Sale` for a transfer receipt and a courier resi under one collection each, and
  `App\Models\FreeItemRedemption` for the resi of a free item handed over. All on
  the private `local` disk.
  v11, not v12: spatie backported `illuminate ^13` into the v11 line, and
  `filament/spatie-laravel-media-library-plugin` pins medialibrary to `^11.0` — so the
  plugin is what holds it there. The second half of this reason used to be v12's
  `php ^8.4` against this project's `^8.3`, and that half is gone: the pin is `^8.4`
  now (see the top of this file). Re-check the plugin before assuming v12 is reachable.
  See Media.
- **filament/spatie-laravel-media-library-plugin v5.7** — the upload field, image column and
  image entry that put medialibrary into the panel. A separate package from Filament, and it
  pins medialibrary to `^11.0`. See Media.
- **barryvdh/laravel-dompdf v3.1** on `dompdf/dompdf` v3 — HTML-to-PDF, facade
  `Barryvdh\DomPDF\Facade\Pdf`. Pure PHP, no headless browser, no system binary. Four reports —
  the cash book, sales, customers and the meter log — built from one abstraction, and three of
  them print the attachments themselves rather than a count of them. v3.1.2 is the first release
  with `illuminate ^13`. See PDF.
- **maatwebsite/excel v4** on `phpoffice/phpspreadsheet` v5 — spreadsheet import and export,
  facade `Maatwebsite\Excel\Facades\Excel`. Four exports, one per feature list screen, over a
  shared `App\Exports\ReportExport` base. **v4, not the
  3.1 line the search results and most tutorials still point at** — see Spreadsheet.
- **`App\Reports\` is the seam between the two.** A `Report` owns what a screen's download
  *says* — the ordering, the eager loads, the per-row line and the totals accumulated as the rows
  are walked — and knows nothing about queues or file formats. Its spreadsheet and its PDF both
  read it, so a figure cannot disagree between two files downloaded five seconds apart. Four
  exist: `CashBook`, `SalesReport`, `CustomerReport`, `MeterReadingReport`. Adding a fifth
  downloadable screen is a `Report`, a `ReportExport`, a Blade view and two one-line subclasses —
  not a copy of the queue plumbing.
- **The queue is load-bearing for four screens.** `QUEUE_CONNECTION=database`, and every export
  is rendered by an `App\Jobs\ExportReport` subclass rather than in the request — so a deploy
  without `queue:work` produces no file, no notification and no error. The finished file is
  announced through Filament's database notifications, which is why the panel calls
  `->databaseNotifications()` and a `notifications` table exists. The **cache** store is
  load-bearing there as well — the job is `ShouldBeUnique`, and that lock lives in the cache, so
  `CACHE_STORE` has to be a store shared across processes. See Keuangan.
- **`CACHE_STORE` has two consumers, and each one rules out a different driver.** The export
  lock above needs a store every *process* can see, including the queue worker — so a `file`
  store split across a web box and a worker box guards nothing. The panel figures in
  `App\Support\PanelCache` need a store that survives between *requests*, which rules out
  `array`, and they are forgotten by name because the `database` store has no tag support.
  Together those leave `database`, `redis` or `memcached`. See the `PanelCache` entry under
  Gotchas.

  `phpunit.xml` sets `array` anyway, and that is correct rather than an exception being made:
  the suite runs `QUEUE_CONNECTION=sync`, so there is no second process for the lock to be
  shared with, and a cache that dies with the test is what keeps one test's cached balance out
  of the next one. `PanelCacheTest` exercises the caching within a single test for that reason.
- **Development is SQLite, production is Postgres, and the suite cannot see the
  difference.** `database/database.sqlite` is gitignored via `database/.gitignore`; tests
  run against `:memory:` (see `phpunit.xml`), so they never touch the dev database — and
  never touch the driver production runs on either. A green suite is therefore not
  evidence that a query works, only that it works on SQLite.

  This has already cost one outage rather than being a theoretical risk. Filament's
  topbar counts unread notifications with `where('data->format', 'filament')`, which the
  Postgres grammar compiles to `"data"->>'format'` — an operator that exists for `json`
  and `jsonb` and not for `text`. Laravel's stock notifications migration declares that
  column as `text`, so **every authenticated page returned a 500 in production** while
  the whole suite stayed green. `/login` stayed up, because the topbar is not rendered on it,
  which is what made it look like a permissions problem rather than a driver one.
  `2026_08_29_140000_use_jsonb_for_notification_data` is the fix.

  Three rules follow, and the last two are the ones easy to skip:

  - **A column queried through a `->` path must be `json`, never `text`**, however
    Laravel's own published migration writes it.
  - **A migration carrying raw SQL guards on the driver** —
    `Schema::getConnection()->getDriverName() !== 'pgsql'` and return. The jsonb migration
    is the worked example, and without that guard it would break `migrate` on every SQLite
    machine including CI.
  - **A driver-specific fix cannot be proven by the suite.** Say so in the migration's
    docblock and verify it against the deployed database by hand, or the next reader
    assumes a green run covered it.
- **In production the app runs behind a proxy** — Caddy terminates TLS on the host and
  forwards to nginx and php-fpm in containers. `bootstrap/app.php` sets
  `trustProxies(at: '*')` for it; without that `url()` and `route()` emit `http://` on an
  `https://` page, assets trip mixed-content blocking and the `secure` session cookie is
  never sent back. Nothing fails loudly — the app renders and the login silently never
  sticks.
- Frontend: Vite 8 + Tailwind 4. Filament ships its own compiled CSS/JS and does not go
  through the app's Vite build — so `resources/css/app.css` styles nothing in the panel, which
  is the whole app. Panel CSS and JS arrive through render hooks instead; see Panel CSS and JS
  under Filament conventions.
- **Two-factor is Filament's own**, not a package — `pragmarx/google2fa-qrcode` and
  `chillerlan/php-qrcode` v5 arrive as Filament dependencies (`filament/filament` requires the
  latter directly). Opt-in per user. See Access control. Note `bacon/bacon-qr-code` is **not**
  installed: google2fa-qrcode only *suggests* it, and its own note says it needs `ext-imagick`.
  Following that suggestion would add a PHP extension to the deploy for a renderer already
  present.
- `laravel/pao` is installed — agent-optimized output for PHP testing tools.
- **Indonesian UI, WIB timestamps.** See Locale and timezone — the timezone choice is not
  reversible without rewriting data.

## Locale and timezone

`APP_LOCALE=id`, `APP_TIMEZONE=Asia/Jakarta`. Write new UI strings in Indonesian.

**Timestamps are stored in WIB, not UTC.** This is the deliberate choice, and it is the one
setting here that cannot be changed later without rewriting every stored timestamp — the values
in the database carry no offset, so moving `app.timezone` silently reinterprets all of them.
When it was first set, existing rows were shifted `+7 hours` by hand across `users`,
`activity_log`, `visits_monitoring`, `authentications_monitoring` and `monitoring_settings`.
That was a one-off fix, not a migration; a migration would re-run elsewhere and shift twice.

**Carbon does not follow `app.locale`.** Laravel dispatches `LocaleUpdated` but ships no
listener for it, so `AppServiceProvider::setCarbonLocale()` calls `Carbon::setLocale()`
explicitly. Delete that and month names and every `diffForHumans()` revert to English while the
rest of the panel stays Indonesian — a change that breaks nothing and shows up only on screen.

Filament reads dates through `translatedFormat()`, so `->dateTime('d M Y H:i:s')` yields
`13 Agt 2026 15:12:25` once Carbon's locale is set. No per-column timezone conversion is
needed anywhere, because storage is already local.

**Translations.** Filament and Shield both ship `id`; nothing was published for them. Laravel's
own lines live in `lang/id` (`validation`, `auth`, `passwords`, `pagination`), translated by
hand from `lang/en`. `validation.php` carries an `attributes` map — without it, messages name
raw columns (`password_confirmation`) in an otherwise Indonesian screen. Add an entry there
whenever a form field is added.

`fallback_locale` stays `en`, so a key missing from `lang/id` degrades to English rather than
rendering as the raw key. That also means a forgotten translation is easy to miss: after
`php artisan lang:publish`, diff `lang/en` against `lang/id` for new rules.

**What stays English on purpose:**

- Activity log `event` keys (`role_granted`, `role_revoked`, `visit_deleted`, `sign_in_deleted`,
  `records_pruned`, `two_factor_reset`, `receipt_deleted`, `meter_photo_deleted`,
  `sale_attachment_deleted`, `redemption_resi_deleted`, `transactions_exported`,
  `sales_exported`, `customers_exported`, `meter_readings_exported`), enum values stored in columns (`income`, `expense` —
  see Keuangan), role names (`super_admin`) and permission names (`Delete:Activity`).
  These are filtered on and asserted in tests. Only the human-readable description is
  translated — `LogRoleChange` and `User::booted()` both map the two separately for exactly
  this reason.
- `/log-viewer`. `opcodesio/log-viewer` ships no language files at all; it is a Vue SPA with
  English baked in. Nothing to translate short of forking it.
- Code, comments and commit messages.

`APP_NAME` is `Internal Web`, so that is what the topbar and browser tab show. Filament
takes the brand name from `config('app.name')` unless `->brandName()` overrides it, and
nothing does — so the login card, the topbar and the `<title>` all follow that one variable.

## Where the rest of it is

Nine sections live in `docs/` rather than here, because they are read while working on one
feature and are dead weight the rest of the time. **Read the file before touching what it
covers** — every one of them records a decision that looks arbitrary from the code and is
expensive to undo.

| File | Covers | True even if you never open it |
|------|--------|--------------------------------|
| `docs/access-control.md` | roles, the panel gate, the log-viewer gate, sign-in identifiers, two-factor | Roles are the only source of truth — there is no `is_admin` column, and any role opens the panel. Either the email or the username signs a user in. |
| `docs/keuangan.md` | the cash book at `/transactions`, its sumber dana at `/sources`, receipts, the queued Excel/PDF export | Amounts are whole rupiah in an integer column, never a decimal. Every row names the wallet the money moved through, and that FK is `restrictOnDelete` — a source in use is retired, never deleted. The export renders on the queue, so no worker means no file **and no error**. Its Bukti column prints the receipt itself, and prints the `thumb` conversion rather than the original — the original still carries the phone's EXIF. |
| `docs/listrik-kost.md` | meter readings at `/meter-readings`, their photographs, the amount paid, the export | One screen, one meter. The panel **records** a bill, it does not compute one: `total_amount` is typed off the bill and `usage_kwh` is derived from the two meter figures, with nothing multiplying the one by the other. Rooms, a versioned tariff table and a rate per kWh all used to sit here and were dropped in turn; the file records what each of those migrations destroyed. |
| `docs/oriflame.md` | sales and customers, the three figures, the item count, the bonus and its handovers, the two exports | The three prices are totals for the whole order; `quantity` counts items and reprices nothing. Ongkir is the consultant's cost, not a line on the customer's bill. The free item is counted **per customer across every order**, never per sale — `Sale::$free_quantity` still exists and has no UI. What was *earned* is derived from the orders; what was *collected* is a recorded row, and the two must not be merged. |
| `docs/monitoring.md` | `/visits`, `/authentications`, `/activities`, retention at `/monitoring` | The package's own six routes are unauthenticated and are disabled by an empty `routes/user-monitoring.php`. Deleting that file brings them all back. |
| `docs/media.md` | medialibrary, the six collections, the private disk, the lightbox | Attachments go on the private `local` disk, and every Filament component rendering one needs `->visibility('private')` — without it the image silently breaks and nothing is logged. A PDF is the one surface that reads them off disk instead, through `App\Support\PdfImage`. |
| `docs/pdf.md` | dompdf, `resources/views/pdf/`, the four reports and their shared partials | dompdf's `chroot` is `base_path()` with `file://` allowed, so user text interpolated into a PDF view can reach `.env`. Escape with `{{ }}`, never `{!! !!}` — an image `src` included, since it is assembled from an uploaded file name. |
| `docs/spreadsheet.md` | maatwebsite/excel v4, `App\Exports\`, the `ReportExport` base | `Worksheet::fromArray()` drops every `0` unless the export implements `WithStrictNullComparison`. Silently — the cell is simply never created. |
| `docs/tests.md` | what each test file locks in, and how to test Filament, PDFs, spreadsheets and signed URLs | Any test that hits a route needs `RefreshDatabase`: every page request writes a visit row. |
| `docs/pwa.md` | installing the panel to an Android or iOS home screen — manifest, icons, service worker | The worker caches the offline page and **nothing else**: a cached saldo cannot be marked stale, and a replayed page carries a dead CSRF token. iOS reads none of the manifest's icons and never offers an install prompt. |

The three features that exist for their own sake are Keuangan, Listrik kost and Oriflame;
everything else in this panel is there to keep them honest.

## Gotchas

**Uploads keep their EXIF.** Medialibrary stores the original file untouched, so GPS
coordinates and device serials from a phone camera survive into whatever disk it lands on — and
on the `public` disk that metadata is fetchable by URL along with the image. Conversions are
re-encoded and lose most of it, but the original is what `getUrl()` returns by default. The
receipt, meter-photo, sale-attachment and redemption-resi screens work around this rather than
solve it: all render the `thumb`
conversion everywhere, so the original is only reached by a deliberate signed request. Nothing
strips the original, and stripping it would be a decision about altering what a user uploaded.

**The exported PDFs are held to the same rule, and there it is load-bearing rather than
incidental.** A screen shows a thumbnail to somebody already inside the panel; an export is a
file that leaves the building with the image *embedded*, so a fallback to the original would
carry the coordinates out with it. `App\Support\PdfImage::path()` therefore returns null when
the conversion is missing rather than reaching for the original — a visible gap in one cell
instead of a silent leak in every one. It is also the only place in this app that reads an
attachment off the disk rather than through a signed URL, which is correct: `enable_remote` is
false, so dompdf cannot fetch a URL, and asking the app for a signed link to a file the renderer
is standing next to would be a request to fetch our own disk.

That matters more for meter photographs than for receipts. A receipt is photographed wherever
it happens to be; a meter is bolted to the building, so its EXIF coordinates are the address of
a property with tenants in it. A sale's attachments sit between the two: a transfer receipt is
usually a screenshot and carries nothing, while a resi photographed at the counter carries
wherever that counter was. The resi on a free-item handover is the same photograph taken for the
same reason, so it sits at the same end of that range.

**User-typed text reaches four kinds of surface, and each escapes differently.** A transaction
description, a meter reading's note, a sale note, a handover's note or tracking number,
a customer's address — and, less obviously, the **name of an uploaded file** — are all free
text somebody typed or chose. Verified against the vendor source rather than assumed, because
the four do not behave alike:

| Surface | What actually happens |
|---------|-----------------------|
| a Blade view | `{{ }}` escapes, `{!! !!}` does not. In a **PDF** view the stakes are higher than XSS: dompdf's `chroot` is `base_path()` and `file://` is in `allowed_protocols`, so parsed markup can reach `.env` — and `APP_KEY` decrypts every user's two-factor secret. See PDF. |
| an **HTML attribute** in a Blade view — today only an image `src` in the four PDF reports | same `{{ }}`, and it is easy to forget it is needed: the value is a filesystem path rather than prose. It is assembled from the uploaded file name, so a quote in that name closes the attribute early and the rest of it is parsed as markup — by the parser in the row above. `App\Support\PdfImage` returns the path; escaping it is the view's job. |
| a Filament description or heading — `->modalDescription()`, `Callout`, `Section`, empty state | all rendered `{{ $description }}`. A plain **`string` is escaped**; an **`Htmlable` is not**, because Laravel's `e()` passes `Htmlable` straight through to `toHtml()`. |
| `Notification::title()` / `::body()` | neither escaped nor raw — both go through `str(...)->sanitizeHtml()`. Scripts and event attributes are stripped, but **markup is still interpreted**, so a customer name containing `<b>` renders bold rather than showing the tag. |

So the trap is narrow and specific: reaching for `HtmlString` to get a list or a line break in a
description, and carrying a user value in with it. Returning a plain string needs nothing.

**Nothing in the project does this today** — the worked example was `RefreshRateAction`, whose
confirmation carried a tariff note into an `HtmlString` and ran it through `e()`, and it was
removed with the tariff screen (see Listrik kost). So the next `HtmlString` written here is the
first one, with no neighbour to copy the escape from. Pair it with a test asserting the escaped
form, because `e()` is one call that reviews cleanly whether it is there or not.

**Cached figures live in `App\Support\PanelCache`, and it is a data cache, not a page
cache.** The panel owns the root path, so its sidebar renders on every screen — each navigation
badge is an aggregate query paid on pages that have nothing to do with it. Two keys are held
across requests: the cash book balance and the overview totals. A third held the current kWh
rate and went with the tariff badge — see Listrik kost.

Full-page HTTP caching was rejected rather than skipped, and for three reasons that are all
silent failures rather than slowdowns:

| What breaks | Why |
|---|---|
| access control | Shield gates every page per user; one user's cached HTML is another user's data |
| the audit trail | `RecordVisit` sits in the panel's own middleware stack, so a response served from cache is a page view `/visits` never sees |
| Livewire | the CSRF token is baked into the markup and goes stale with the page; `databaseNotificationsPolling('30s')` polls a frozen page |

Three things to know before adding a key:

- **`CACHE_STORE=database` has no tags.** `Cache::tags()` throws on that store rather than
  degrading, so every key is a named constant on `PanelCache` and is forgotten by name from the
  model that changes it. That store is shared with the `ExportCashBook` unique-job lock — see
  the queue note above — so it has to stay a driver every process can see.
- **`Cache::remember()` treats `null` as a miss**, so a figure whose real answer is "none"
  re-runs its query on every page load while the cache still looks like it is working.
  `PanelCache::remember()` wraps the value in an array to make null a hit. Nothing cached today
  returns null — the tariff badge that did was removed — so
  `PanelCacheTest::test_a_null_value_is_cached_rather_than_re_queried` asserts the wrapper
  directly. It is the only thing standing between that wrapper and a tidy-looking deletion.
- **The cache stops at the presentation layer.** A badge may serve a stale figure; anything a
  bill is computed from may not. That line used to be held by keeping the kWh badge off the
  method the reading form defaulted its rate from; then by there being no shared rate at all,
  every reading carrying its own; and now by nothing computing a bill anywhere — the amount is
  typed (see Listrik kost). Re-derive the rule before caching any figure a form defaults from: a
  stale badge is a wrong sidebar, a stale default is a wrong bill, permanently.

Invalidation is event-driven from `Transaction::booted()` — `saved` *and* `deleted`, because
either alone misses half the writes. Model events are the whole mechanism, so the way a badge
goes stale is any way of changing a row without firing one: a raw `UPDATE` in tinker or sqlite.
`php artisan cache:clear` is the escape hatch, and it costs at most two queries on the next page
load — one per key.

It is safe to run against a queued export, and not by luck: `DatabaseStore::flush()` deletes the
`cache` table only, while a `ShouldBeUnique` lock lives in `cache_locks`, and `cache:clear`
touches that second table only when given `--locks`. So the unique guard on `ExportCashBook`
survives a badge flush. Reaching for `--locks` to unstick something is the move that would break
it.

**A static memo is not a cache, and the two are stacked on purpose.** `TransactionResource::$balance`
holds the figure for the rest of the request because Filament asks for a badge and its colour in
two separate calls; `PanelCache` holds it across requests. Dropping the static puts a cache
round-trip on the second call, dropping the cache puts the aggregate back on every page load.
The static assumes a process that ends with the response — under Octane or any persistent worker
it would outlive its request. It already outlives a *test*, which is why `PanelCacheTest` resets
it in `setUp()`; without that a test reads what the previous test left behind and the cache layer
is never exercised.

**Policies for vendor models are not auto-discovered.** Laravel maps `App\Models\X` to
`App\Policies\XPolicy`. `Activity` lives in a vendor namespace, so `ActivityPolicy` is
registered by hand in `AppServiceProvider::registerVendorModelPolicies()`. Without that line
the policy is silently ignored and every permission check on it passes. Shield prints a
"requires registration" note when generating such policies — do not skip it.

**Shield prints that note for `RolePolicy` too, and there it is wrong.** Its own service
provider registers that one, so nothing needs doing. The note is emitted from the namespace of
the model, not from whether a binding exists, so it cannot tell the two cases apart. Check with
`Gate::getPolicyFor(Model::class)` rather than trusting the label in either direction — today
that returns `App\Policies\RolePolicy` for Shield's `Role`, `App\Policies\ActivityPolicy` for
activitylog's `Activity`, and **`null` for medialibrary's `Media`**.

`App\Models\VisitMonitoring` and `App\Models\AuthenticationMonitoring` exist only to dodge this
trap: they subclass the package models so discovery reaches them, and Shield generated their
policies without a "requires registration" note. They also pin `getTable()` to the config key,
because the package models hardcode `$table` while its middleware inserts into
`config('user-monitoring.*.table')`.

**Model config uses PHP attributes, not properties.** `app/Models/User.php` declares
`#[Fillable([...])]` and `#[Hidden([...])]` above the class. Do not add `protected $fillable`
alongside them — pick the attribute style the file already uses. Other models use plain
properties; match the file you are editing.

`#[Fillable]` on `User` lists four columns — `name`, `username`, `email`, `password` — and must
keep listing exactly those. The two-factor columns are absent on purpose: they are written by direct assignment, and a fillable secret is
settable from any request that reaches a user form.

**`booted()` is already taken on seven models.** Eloquent allows one `booted()` per class, so a
second definition silently replaces the first rather than erroring — no warning, and the hook
that vanishes is whichever one was there first. Add listeners inside the existing method.

| Model | What its `booted()` does |
|-------|--------------------------|
| `User` | watches the two-factor column for change without recording its value |
| `Transaction`, `MeterReading`, `Sale`, `FreeItemRedemption` | stamp the author from the session |
| `VisitMonitoring`, `AuthenticationMonitoring` | write the `visit_deleted` / `sign_in_deleted` audit entries |

Trait boot methods are exempt: `bootInteractsWithMedia()` runs *in addition to* `booted()`,
which is why the two coexist on `Transaction`, `MeterReading`, `Sale` and
`FreeItemRedemption` — all four stamp or watch something from `booted()` while medialibrary
registers its own hooks alongside.

**A `unique` column is not a unique value on SQLite.** `=` on a TEXT column is case sensitive
there, and Laravel's `unique` rule compares with `=` — so `bca` is inserted happily beside `BCA`
and the index raises nothing. On a name that something is *grouped by*, that is not a cosmetic
duplicate: `Source` is the worked example, where the two rows split one account's balance in
half and neither figure is wrong on its own. Two halves of the fix, and both are needed:
`Source::setNameAttribute()` collapses whitespace on write, because `"BCA "` clears the index
too and is invisible on screen; the form pairs it with a closure rule comparing
`lower(name)`, since `Rule::unique()` cannot be talked into a case-insensitive comparison
without a `whereRaw` that replaces its own `where` rather than adding to it. The same trap is
what folds usernames to lowercase — see Seeded credentials.

**A column may be nullable while its form field is required, and the tests are where that
splits.** `transactions.source_id` is the worked example: rows recorded before the column
existed have no answer and backfilling one would be inventing a financial record, so the column
allows null while `TransactionForm` refuses it from everything recorded since. Two consequences.
A **factory follows the column, not the form** — `TransactionFactory` leaves `source_id` null —
so any test that presses `save()` on a form it did not fill now fails validation on a field it
never mentioned, and the failure reads as whatever that test was actually about.
`EditRedirectTest::savable()` is where that is absorbed, and it is deliberately a helper with a
comment rather than a factory default: making the factory attach a source would put a `sources`
row behind every transaction in the suite and quietly change what the per-source recap in
`TransactionExportTest` is asserting. And **the null case still has to be rendered** — a screen,
a spreadsheet cell and a PDF cell each need to say "Tidak diketahui" rather than leave a blank,
which reads as a column that was forgotten. `CashBook::UNKNOWN_SOURCE` is that string, spelled
once so the ledger row and the recap under it cannot disagree.

**`permission.events_enabled` is set to `true` on purpose.** It ships as `false`. Role grants
and revocations are audited through those events, so turning it off silently removes the
privilege-escalation trail.

**Never `Hash::make()` a password in seeders or factories.** `User::casts()` sets
`'password' => 'hashed'`, so Eloquent hashes on assign. Hashing first produces a double hash
and login silently fails.

**A seeded password never meets the panel's own rule.** `UserForm` puts `Password::default()`
on the field — Laravel's bare default, so `min(8)` and nothing else, because nothing here calls
`Password::defaults()` to configure it — with `->confirmed()` beside it. `AdminUserSeeder`
writes through `User::updateOrCreate()`, which is a plain model write: no form, no rules, no
confirmation. The account this project shipped with was `admin`, five characters — a value the
panel would have refused from its own create screen. Whatever is seeded there now, the bypass is
structural rather than an oversight: validation lives on the form and the seeder does not go
through one, so a seeded credential is only ever as good as the literal typed into the file.

**Seeders do not write audit entries.** `AdminUserSeeder` uses `WithoutModelEvents`, and
`LogsActivity` hooks model events. Granting admin through a seeder therefore leaves no trace
in `activity_log` — and neither does *changing* that account. `name` and `username` are both on
the `User` allowlist, so the same edit made at `/users` is audited and the same edit made in the
seeder is not; the row just has a different identity the next time somebody looks.

**activitylog v5 moved almost everything.** Copying v4 snippets fails on all three:

| v4 | v5 |
|----|----|
| `Spatie\Activitylog\Traits\LogsActivity` | `Spatie\Activitylog\Models\Concerns\LogsActivity` |
| `Spatie\Activitylog\LogOptions` | `Spatie\Activitylog\Support\LogOptions` |
| `dontSubmitEmptyLogs()` | `dontLogEmptyChanges()` |

v5 also stores diffs in their own `attribute_changes` column (`['old' => [...], 'attributes'
=> [...]]`) instead of burying them inside `properties`.

**Filament published assets are gitignored** (`/public/css/filament`, `/public/js/filament`,
`/public/fonts/filament`). They are regenerated by `php artisan filament:assets`, which
`composer install`/`update` already triggers through the `post-autoload-dump` →
`filament:upgrade` script. A deploy that skips composer scripts ships a panel with no CSS.

**Panel has its own middleware stack** defined in `AdminPanelProvider`, independent of
`bootstrap/app.php`. Middleware added to the app's `web` group does not apply to the panel —
which, since the panel sits at the root path, is nearly the whole app. `RecordVisit` is the
worked example: it is listed in both places, and the only page left in the `web` group is
`/log-viewer` (`/up` is registered with no group at all). That is what
`UserMonitoringTest::test_a_page_view_outside_the_panel_is_recorded` covers — every other
visit test now goes through the panel stack, so without it the `web`-group registration could
be deleted with a green suite.

**Any test that hits a route needs `RefreshDatabase`.** Every page request writes to
`visits_monitoring` — the panel stack and the `web` group both run `RecordVisit`, so a `GET`
anywhere but `/up` is a write. `RecordVisit` survives a missing table, but a test without
migrations only proves the fallback works. `ExampleTest` was changed for this.

**A guest can reach exactly one page: `/login`.** The panel owns the root path and everything
behind it needs a role, so a test that wants an anonymous 200 has to ask for the login screen —
there is no public page left. That is deliberate on the recording side too: `RecordVisit` sits
in the panel's *base* stack rather than its `authMiddleware()`, so signed-out hits are logged.
The visit tests in `UserMonitoringTest` are written against `/login` for this reason.

**Indonesian has no plural inflection.** Filament pluralises a resource's `$modelLabel` unless
`$pluralModelLabel` is set too, which produces "Penggunas". Every resource sets both to the
same word.

## Seeded credentials

`DatabaseSeeder` calls `ShieldSeeder` then `AdminUserSeeder` — that order matters, because the
admin account is made usable by `syncRoles([super_admin])` and the role has to exist first.
The account is `admin@admin.com` / `Sinambela#123`, its name is `ZUNEDI` and its username is
`zunedi` — either identifier signs it in (see Sign-in identifiers). The username is lowercase
because usernames are stored folded and SQLite compares TEXT case sensitively; `Zunedi` would be
an unknown account.

**The address is the lookup key, not the identity.** `updateOrCreate` matches on
`admin@admin.com`, so re-seeding after a change to the name, username or password *updates the
existing row* rather than adding a second admin — and equally, changing the address in the
seeder would leave the old account behind, still holding `super_admin`. Change the address only
together with a plan for the row already carrying it.

The password reads like a real one and is committed to the repository, which makes it a local
credential no matter how it reads — anyone with the repo has it. There is no environment guard
on the seeder either, so do not run `--seed` against a production database, and rotate the
password from `/profile` on anything that is not a dev machine.

The seeded account has no second factor, and cannot be given one from a seeder — the secret has
to be paired with a phone at `/profile`. Anywhere this account exists, two-factor is
protecting nothing.

`ShieldSeeder` is generated, not hand-written: `php artisan shield:seeder --force` snapshots
the current roles and permissions into it. Regenerate it whenever permissions change, or a
fresh database will come up missing them.

## Audit log

`User` uses `LogsActivity` with an explicit allowlist:
`logOnly(['name', 'username', 'email'])`,
`logOnlyDirty()`, `dontLogEmptyChanges()`, log name `user`. The allowlist is deliberate — the
table holds password hashes and remember tokens, and `logAll()` cannot stay safe as columns are
added.

**Two-factor changes are audited separately too**, and for the opposite reason: the column is
right there, but its value is the secret itself. `User::booted()` watches whether it changed
rather than what it changed to. Full rules in Access control.

**Role changes are audited separately.** `LogsActivity` watches columns, and roles live in a
pivot table, so it cannot see them at all. `App\Listeners\LogRoleChange` listens for
`RoleAttachedEvent` / `RoleDetachedEvent` and writes `role_granted` / `role_revoked` entries
with the role names in `properties`. Since a role is what grants panel access, this *is* the
privilege-escalation trail — if it stops working the log looks healthy while missing the most
important events.

**Seven models carry the trait**, each with its own log name and its own explicit allowlist:

| Model | Log name | Feature |
|-------|----------|---------|
| `User` | `user` | Access control |
| `Transaction`, `Source` | `transaction`, `source` | Keuangan |
| `MeterReading` | `meter_reading` | Listrik kost |
| `Customer`, `Sale`, `FreeItemRedemption` | `customer`, `sale`, `free_item_redemption` | Oriflame |

Five of them pair the trait with a separate listener, because the thing worth auditing is not a
column and `LogsActivity` cannot see it: roles on `User` (a pivot table), receipts on
`Transaction`, photographs on `MeterReading`, attachments on `Sale` and the resi on
`FreeItemRedemption` (all relations).

When adding the trait to another model, keep the same shape: name the log, list attributes
explicitly, and add a test asserting nothing outside the allowlist reaches `attribute_changes`.
`UserActivityLoggingTest`, `TransactionResourceTest`,
`SaleResourceTest::test_nothing_outside_the_allowlist_is_logged` and
`MeterReadingResourceTest::test_nothing_outside_the_allowlist_is_logged` each have one to copy.
That last one closed what used to be a three-model gap in Kost: an allowlist is asserted
everywhere by what it *does* record and almost nowhere by what it refuses, so widening a
`logOnly()` call — or adding a column a refactor sweeps into it — fails nothing.

The UI is `app/Filament/Resources/Activities/`. `canCreate()` and `canEdit()` return `false`,
so Filament never registers create or edit routes — an editable audit entry is worse than a
deleted one, because it still reads as true. Keep it that way. Deletion **is** allowed, gated
by `Delete:Activity` / `DeleteAny:Activity` and logged to the file log; see Monitoring for the
full chain. The query eager-loads `causer` and `subject` because both are morphs and cannot be
joined.

Log names in use: `user` (model changes including either sign-in identifier, role grants,
two-factor changes), `transaction`
(cash book rows and receipt deletions — see Keuangan), `meter_reading`
(the electricity feature and its photo deletions — see Listrik kost), `sale`
(orders and their attachment deletions), `free_item_redemption` (a free item collected, and its
resi photograph removed — kept out of `sale` and `customer` because "when did somebody collect a
free item" is a question read past both of them otherwise) and `customer` (the Oriflame feature
— see Oriflame),
`source` (wallets and accounts added, renamed or retired — kept out of `transaction` because
"who changed what BCA is called" is a question read past every cash book row otherwise),
and `monitoring`
(deletions, prunes, and every export from the four feature list screens — a read that leaves the
panel is recorded here rather than under the feature's own log name, because it is an operation
on the book rather than a change to it; the export entry is written by the queued job rather than
by the request, so its causer is passed explicitly. One event key per screen, not one shared key:
all four write under `monitoring`, so a shared key would make "who took a copy of the customer
list" unanswerable without reading every properties blob. The *format* is a property rather than
a second key, because downloading a report is one act and the file extension is a detail of it).
Descriptions are Indonesian; `event` keys are not — see Locale and timezone.

## Filament conventions

- Resources, Pages and Widgets are auto-discovered from `app/Filament/{Resources,Pages,Widgets}`.
  Creating a class there is enough; no manual registration. `Filament/Widgets` does not currently
  exist — the panel provider still points `discoverWidgets()` at it, which is harmless, but see
  the dashboard note below before creating it.
- **Discovery walks the whole tree, subdirectories included, and filters in a fixed order:**
  `class_exists()`, then not-abstract, then `is_subclass_of($baseClass)`, then `isDiscovered()`.
  That order is why `app/Filament/Resources/Concerns/` can sit inside the scanned directory
  holding shared page behaviour — `ReturnsToListAfterSaving` is a **trait**, and `class_exists()`
  is `false` for a trait, so it is dropped on the *first* check rather than on the base-class one.
  An abstract base class put there would be dropped on the second.
  The gap is between the third check and the two before it: a **concrete** class in that tree
  extending `Livewire\Component` is queued for Livewire registration *before* the `$baseClass`
  filter runs, so it is registered even though it is not a resource. Nothing does that today. If
  something needs to, `php artisan route:list` and
  `Filament::getPanel('admin')->getResources()` are the two things to check — the latter returns
  nine, and a helper class that accidentally becomes the tenth costs nothing visible until it
  collides with a slug (see the empty-path note under Stack notes).
  **`app/Filament/Actions/` sits beside the three scanned directories and is not one of them.**
  `discoverResources()`, `discoverPages()` and `discoverWidgets()` each name their directory
  explicitly, so a fourth sibling is invisible to all three — which is why the cross-resource
  action base lives there rather than under a resource. Adding a directory under `app/Filament`
  is safe; adding one *inside* `Resources`, `Pages` or `Widgets` is what the filter order above
  is about.
- Generate with `php artisan make:filament-resource`, `make:filament-page`, `make:filament-widget`.
- v5 renamed the filter builder: use `->schema([...])`, not the deprecated `->form([...])`.
- **A page's body is a schema, not Blade.** v5 has no `filament-panels::form.actions`
  component — that is v3. Build the page in `content(Schema $schema)` out of
  `Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')->footer([Actions::make(...)])`,
  and let the Blade view be a wrapper that renders `{{ $this->content }}`. Copy
  `Filament\Auth\Pages\EditProfile` and `MonitoringSettings` for the shape.
  `Filament\Schemas\Components\Callout` covers status banners.
- Custom pages need `HasPageShield` for Shield to gate them; without it `canAccess()` falls
  back to the parent and the permission Shield generated is never checked.
- **Filament's auth pages do not share one base class, and discovery treats them differently.**
  `discoverPages()` registers a route for every `Filament\Pages\Page` subclass in the scanned
  directory. `Login` extends `SimplePage` — a `BasePage`, not a `Page` — so it is passed over and
  only queued as a Livewire component; `EditProfile` extends `Page` and ships
  `$isDiscovered = false` precisely because it would not be. Check the parent before putting a
  replacement auth page in `app/Filament/Pages`, or keep it outside the scan the way
  `App\Filament\Auth\Login` does and wire it with `->login(Login::class)`.
- **Renaming a field on an auth page orphans its error message.** Filament's `Login` throws its
  failure against `data.email` by name, and Livewire raises nothing for a message on a key the
  form does not have — the page reloads with no explanation for a wrong password and for an
  unknown account alike. Override `throwFailureValidationException()` alongside the field. See
  Sign-in identifiers.
- Infolist `KeyValueEntry` renders scalars only. Non-scalar values must be stringified first —
  see `ActivityInfolist::stringifyValues()`.
- `CodeEntry` requires the `phiki` package, which is not installed. Use a `TextEntry` with
  `FontFamily::Mono` for JSON blobs instead.
- Password fields on an edit form must use `->dehydrated(fn ($state) => filled($state))`, or
  saving any other field overwrites the stored hash with an empty string and locks the account
  out silently. A confirmation field pairs with `->confirmed()` and `->dehydrated(false)`.
- Put record-level authorization on the resource (`canDelete()` etc.), not on the action button.
  Filament consults the resource for row actions *and* for every record inside a bulk action;
  a check on `->visible()` alone leaves the bulk path open. `UserResource::canDelete()` refuses
  self-deletion this way. To defer to a Shield policy instead, leave the method unoverridden —
  do not return `true`.
  **The same method is also where a database constraint becomes a missing button rather than an
  error page.** `SourceResource::canDelete()` is the worked example: the foreign key on
  `transactions.source_id` is `restrictOnDelete`, so deleting a source in use is refused by
  SQLite — as a `QueryException` thrown mid-action, which is a stack trace where a user expected
  a row to disappear. Asking the same question in `canDelete()` turns that into an action that
  is simply not offered. Note it needs the count to already be loaded (`withCount` in
  `getEloquentQuery()`) or it is a query per row, and the bulk path calls it once per selected
  record — which is exactly why the check cannot live on the button.
- `DeleteBulkAction::make()->fetchSelectedRecords()` whenever anything hangs off the `deleted`
  model event. The default is currently `true`, but relying on a vendor default for an audit
  trail is how one goes missing on an upgrade.
- Dashboard widgets: `AccountWidget` only. Filament's default `FilamentInfoWidget` (version /
  docs / GitHub branding card) was deliberately removed from `->widgets([...])` — do not add it
  back when regenerating or upgrading the panel provider.
- **A widget in `app/Filament/Widgets` is a dashboard widget.** `discoverWidgets()` scans that
  directory and everything it finds renders on the dashboard, which anyone holding any role can
  open. A widget that shows data guarded by a resource policy belongs under that resource
  (`Resources/<Name>/Widgets/`) and in its page's `getHeaderWidgets()`, where the policy is
  already enforced. `TransactionOverview` is the worked example.
- **A relation manager on a *view* page is read-only until told otherwise, and it fails
  silently.** `RelationManager::isReadOnly()` returns true for any page subclassing
  `ViewRecord` while the panel keeps its default
  `readOnlyRelationManagersOnResourceViewPagesByDefault`, and the result is not an error: the
  table renders, the rows are there, and the create, edit and delete actions are simply absent —
  indistinguishable from a permission the user has not been granted. Override `isReadOnly()` on
  the manager that needs writing rather than flipping the panel flag, which would quietly change
  every manager added afterwards. `FreeItemRedemptionsRelationManager` is the worked example.
  Two more things about them, both worth knowing before writing the test:
  - **It authorizes against the *related* model's policy**, not the owner's — `canViewForRecord()`
    calls `authorize('viewAny', $relatedModel)`, and the row actions consult that policy too. So a
    model reached only through a relation manager still needs a policy, and Shield will not have
    generated one: Shield builds permissions from **resources**, so a model with no resource has
    no `ViewAny:Thing` permission for a generated policy to check, and one naming it would refuse
    everybody including a super admin. Write the policy by hand against the *owner's* permissions
    — `FreeItemRedemptionPolicy` maps onto `View:Customer` / `Update:Customer` and records why.
    Auto-discovery still applies, so `App\Models\X` → `App\Policies\XPolicy` needs no
    registration.
  - **It is lazy by default**, so the first response carries a placeholder and the rows arrive on
    a second Livewire request. A test doing `get(...)->assertSee($row)` fails against a manager
    that works; drive the component with `Livewire::test($manager::class, ['ownerRecord' => …,
    'pageClass' => …])` instead, and keep the page test to what the page itself renders.
- **Simpan on an edit screen returns to the list, on the four feature resources only.**
  `App\Filament\Resources\Concerns\ReturnsToListAfterSaving` overrides `getRedirectUrl()`, and
  `EditTransaction`, `EditSale`, `EditCustomer` and `EditMeterReading` use it. Filament's own
  default is to **stay**: `EditRecord::getRedirectUrl()` returns `null` for as long as the user may
  still access the page it is on, and only leaves when that authorization has just been lost — so
  the ordinary successful save is precisely the case that never moves, and a form long enough to
  have scrolled reports itself with a notification above the fold.
  The panel offers `->resourceEditPageRedirect('index')`, one line against four, and it was
  rejected for the same reason as `readOnlyRelationManagersOnResourceViewPagesByDefault` above: it
  is a *default*, so every resource added afterwards inherits it silently, including one where
  staying on the form is right. `EditUser` is deliberately outside the set, and
  `EditRedirectTest::test_a_screen_without_the_trait_stays_on_its_form` is the assertion that
  refuses the trade — swap the trait for the flag and the four positive tests stay green.
  Two consequences of leaving. **The list is rebuilt from scratch**: no table here calls
  `persistFiltersInSession()`, so a filter, a search term and the page number are all gone by the
  time the edited row is back on screen — staying on the form used to make browser-back restore
  all three. And **the write is never traded for the redirect**: `EditRecord::save()` writes,
  commits the transaction, sends the saved notification and reads `getRedirectUrl()` last.
- `TextColumn::money()` does **not** divide by 100 — its `$divideBy` parameter defaults to `0`,
  which is falsy, so the state is formatted as given. Pass `divideBy: 100` explicitly for a
  column stored in minor units. It also renders two decimal places unless given
  `decimalPlaces: 0`, and it cannot prefix a sign, which is why Keuangan formats amounts with
  `number_format()` instead.
- **`TextInput::mask()` does nothing here.** Filament v5's Alpine build registers six directives
  — `float`, `load-css`, `load-js`, `mousetrap`, `sortable`, `tooltip` — and two magics,
  `float` and `tooltip`. There is no `mask` directive and no `$money` magic; grepping the whole
  of `vendor/filament/*/dist` for `x-mask` or `$money` returns nothing, so no asset registers
  them either.
  `mask()` still renders `x-mask` (or `x-mask:dynamic`), so the widely-quoted
  `->mask(RawJs::make('$money($input)'))` produces an attribute nothing reads: no error, no
  formatting. Either format server-side on `->live(onBlur: true)` the way Keuangan does, or
  register `@alpinejs/mask` as a panel asset first — Filament's own assets are gitignored and
  rebuilt by `filament:assets`, so that is a build-pipeline decision, not a one-liner.
- **`->numeric()` and `->integer()` force `type="number"`**, through `TextInput::getType()`.
  A number input cannot show a grouped value, so any field that formats its own state has to
  drop both — and with them go the `numeric` / `integer` / `min` / `max` rules they registered.
  Replace them explicitly or the field ends up with no validation at all.
- **An action that should change the form rather than the record writes to
  `$livewire->data`.** `EditRecord::$data` is the public form state array, so an action can put
  values in front of the user and let the ordinary Simpan commit them — which keeps the model
  events, the validation and the audit entries on the normal path. Write values in the shape the
  field *holds* — a `RupiahInput` holds a grouped string, not an integer, and a
  `DateTimePicker` configured `->seconds(false)` holds `Y-m-d H:i`. **Nothing does this today**:
  `RefreshRateAction` was the worked example and went with the tariff screen (see Listrik kost),
  so the next one is written from this note rather than from a neighbour. Two things that were
  learned the hard way there and are cheap to lose: pair it with a test asserting the row is
  *unchanged* until Simpan, or it quietly becomes a direct write; and if the field being written
  is hidden, something else on screen has to move as evidence — a `TextEntry` reading `$get()`
  re-renders, a hidden input does not. There is no `Repeater` left in this project, but if one
  returns: its items live under `data.<field>.<uuid>.<name>`, keyed by uuid, so the path cannot
  be written out in advance — iterate the array instead.
- **A grouped rupiah field is `App\Filament\Forms\Components\RupiahInput`.** It assembles the
  `->live(onBlur)` / `->formatStateUsing()` / `->dehydrateStateUsing()` trio that has to travel
  together — losing the last one stores `"1.500.000"` into an INTEGER column, which SQLite
  casts to **1** with no error. Use `->notGreaterThan()` rather than Laravel's `->lte()` to
  compare two of them; `lte` decides how to compare from `is_numeric()`, which reads
  `"150.000"` as a number and `"1.500.000"` as a string length. `->allowingZero()` lifts the
  `WholeRupiah` floor for a field where nothing is a real answer — the ongkir on a sale handed
  over rather than posted, and the bill for a month the meter did not move. Narrow the range
  with a further `->rule(new WholeRupiah(max: …))` where a typed figure has no plausible
  ceiling of its own: Filament appends rules, so narrowing composes and widening by accident is
  impossible. See Oriflame and Listrik kost.
  `Transaction::$amount` is the one field left spelling the trio out inline rather than using
  this component; converting it is a change to tested financial code, not a tidy-up.
  **The PDF reports have their own formatter and it is not this one.** `App\Support\Rupiah`
  prints a figure for print rather than for a form, and the difference is not cosmetic: it uses
  an ASCII hyphen because U+2212 is absent from Helvetica's WinAnsi and dompdf drops a missing
  glyph in silence, which would print a negative figure as a positive one. See PDF.
- **`->stripCharacters()` is validation-only.** `TextInput::mutateStateForValidation()` applies
  it; `mutateDehydratedState()` does not. What is *stored* is the unstripped state, so pair it
  with `->dehydrateStateUsing()` — or the rules see one value and the column receives another.
- An action that weakens someone else's security should ask for the actor's **own** password:
  `TextInput::make('password')->password()->required()->currentPassword()`.
  `->requiresConfirmation()` stops a misclick; it does not stop a passer-by at an unlocked
  screen. `ResetTwoFactorAction` is the worked example.
- Actions mounted in more than one place (a table row *and* a page header) belong in their own
  class under `Resources/<Name>/Actions/`, returning a configured `Action` from a static
  `make()`. Filament's own MFA actions have that shape. Two copies of an authorization rule is
  one copy too many. Variants of one action live in the *same* class as several static
  factories over a shared private base — `ExportRecordsAction::excel()` and `::pdf()`
  differ only in the renderer, and splitting them would duplicate the gate and the audit call.
  **And an action repeated across resources belongs in `app/Filament/Actions/`**, with a subclass
  per resource naming only what differs. `ExportRecordsAction` is the worked example: four
  screens export, and what varies between them is three methods — the job, the gate and the
  qualified primary key. The rest, including the "sedang diproses" notification and the
  `getFilteredTableQuery()` call that must not become `getFilteredSortedTableQuery()`, is written
  once.
  **More than one mount point is sufficient reason, not the only one.** An action carrying real
  logic — a built-out confirmation, a state diff, a notification — belongs in its own class from
  the first mount, so the page class stays a list of what is on the page.
  `RefreshRateAction` used to be mounted once and to be a class for that reason alone; a
  `getHeaderActions()` holding sixty lines of arithmetic is where a page stops being readable.
- **A media component repeated per collection belongs in a private factory method** on the schema
  or table class, not typed out twice. `MeterReadingForm`, `MeterReadingsTable` and
  `MeterReadingInfolist` each build both of their photo components from one `photos()` helper;
  `SaleForm`, `SalesTable` and `SaleInfolist` do the same through `attachments()`. The reason is
  the failure mode rather than the line count: the flag that matters most on these,
  `->visibility('private')`, produces a broken image and nothing in the log when it goes missing
  from one copy, so a second copy is a second chance to lose it silently. Six call sites across
  two features, two helpers — not six chances.
  The helper is also where a per-collection *difference* belongs, rather than an argument against
  one: `SaleInfolist::attachments()` derives each entry's `data-lightbox` key from the collection
  it was handed, so the two evidence strips stay separate viewers without either call being
  written out twice. See Media.
  **The PDF reports answer the same question with a partial rather than a method.**
  `resources/views/pdf/partials/` holds the stylesheet, the heading, the summary cards and the
  evidence cell; a report view is its `<table>` and nothing else. Same failure mode, one step
  worse: dompdf raises nothing for a rule it cannot parse — `show_warnings` is `false` — so a
  stylesheet copied into a fifth view and edited there produces a valid document that has quietly
  stopped matching the other four. See PDF.
- **An action that returns a download must return a `BinaryFileResponse` or a
  `StreamedResponse`.** Livewire's `SupportFileDownloads` intercepts exactly those two; any
  other response object falls through to the ordinary return path and Livewire tries to
  JSON-encode it, throwing **`Type is not supported`** — a message that names neither the
  action nor the response. `Excel::download()` already returns the right type;
  `Pdf::download()` does not, so wrap it in `response()->streamDownload(...)`. See PDF.
  **An action that queues the render returns nothing at all**, which sidesteps this entirely —
  and buys a different obligation: the request has ended before the file exists, so a flash
  message can no longer deliver it. `ExportRecordsAction` flashes "sedang diproses" and
  `ExportReport` sends a database notification carrying a signed link. Dropping the second
  half leaves a job that writes a file nobody is ever told about.
- **A double click is guarded on the job, not on the button.** `->disabled()` after a click, a
  `->requiresConfirmation()`, a spinner — none of them survive a second browser tab, a
  double-submit, or a user who reloads and clicks again, and all of them are client state. The
  server-side answer is `ShouldBeUnique` with a `uniqueId()` that describes the *request*, so a
  genuine repeat is refused and a changed one is not. `ExportReport` is the worked example, and
  the sharp edge is that a wrong key fails silently in both directions — too broad and it
  discards legitimate work, too narrow and it guards nothing. See Keuangan.
  **The job class is part of the key and does not have to be written into it.** Laravel's
  `UniqueLock::getKey()` prefixes `get_class($job)`, so a class per report is what keeps one
  screen's guard from cancelling another's export over rows that happen to share ids — which is
  the whole reason `ExportCashBook`, `ExportSales`, `ExportCustomers` and `ExportMeterReadings`
  are four classes carrying one line each rather than one job taking a report argument.
- **A `Select` built from a relationship silently drops the value it is showing if the option is
  filtered out.** `->relationship(..., modifyQueryUsing:)` builds the options *and* resolves the
  current state's label from the same query, so a filter that excludes the row this record
  already points at renders an empty field on a record that plainly has one — and the ordinary
  Simpan then writes null to the column, with no validation error, because an empty select is a
  legitimate submission. `TransactionForm`'s sumber dana field is the worked example: the filter
  is "active", the record's own source is added back by `Source::scopeSelectable($keep)`, and
  `SourceResourceTest::test_a_retired_source_stays_selectable_on_the_row_already_using_it` is
  what stands between that clause and a simplification that reviews cleanly. `$record` is
  injectable into that closure by name, and is null on a create form.
  The general shape: any predicate narrowing a relationship's options is about what may be
  *chosen from now on*, never about what may be *read back*. The table filter on the same column
  is the other side of it and deliberately offers every source, retired ones included.
- **A hidden field is not saved.** `->hidden()` / `->visible(false)` makes `isDehydrated()`
  return false, and the component's state path is stripped from the payload — so a field hidden
  to tidy a form silently stops writing its column. Pair it with `->dehydratedWhenHidden()`
  whenever the value still has to reach the row, and assert the stored value in a test:
  the form shows no error, and the failure surfaces as a NOT NULL violation naming a field the
  user cannot see. **No field in this project is hidden today** — `MeterReadingForm`'s rate was
  the worked example, became an ordinary visible field when the tariff screen supplying it was
  removed, and has since been replaced altogether by a typed amount (see Listrik kost), so the
  flag appears nowhere and the next hidden field is written from this note alone.
- **A value written into a date picker has to match that picker's own precision**, whether it
  arrives from `->default()`, from `$set()` or from an `afterStateUpdated()`. A `DateTimePicker`
  configured `->seconds(false)` carries state as `Y-m-d H:i`, so writing `Y-m-d H:i:s` puts a
  shape in the form state that the field never produces on its own. It still displays and still
  saves, so nothing fails — but `assertSchemaStateSet()` compares the raw string, and every test
  written against the field's natural output disagrees with it. `MeterReadingForm` formats its
  prefill to match: `->default()` there returns the previous reading's `end_read_at` through
  `->format('Y-m-d H:i')` rather than handing the picker a Carbon instance.
- **The same derived figure reaches a list and a detail screen by two different routes, so the
  arithmetic gets one home.** A total that walks a loaded relation is right on a view screen and
  is a query per row on a list; the list therefore asks the database for it with
  `->counts()` / `->sum('relation', 'column')` from
  `Filament\Support\Concerns\CanAggregateRelatedModels`, which lands as a single subquery and
  arrives as `sales_sum_quantity` — **`null`, not `0`, when the relation is empty**. Whatever is
  computed on top of that figure must not be written twice: `Customer::freeItemsFor(?int)` is the
  worked example, called by the accessor the view screen reads and by the table column that reads
  the subquery, so the two cannot start disagreeing about what twenty items are worth. See
  Oriflame.
  **One column carries one aggregate**: `->sum()` sets a single relationship/column pair, so a
  figure needing a *second* one — the customer list needs items bought *and* free items collected
  — asks for it on the table query instead, with
  `->modifyQueryUsing(fn (Builder $q) => $q->withSum('freeItemRedemptions', 'quantity'))`. Same
  subquery, same `null`-when-empty, and no per-row walk of a second relation.
  A figure that is only ever read *beside* the one it derives from belongs on that column's
  `->description(..., position: 'below')` rather than in a column of its own — a column of mostly
  zeroes costs a row's width to say nothing, and on a derived value it would also be a sort
  control with no expression behind it, which is the next bullet.
- **A column with nothing behind it cannot sort itself.** `TextColumn::make('usage_kwh')` fed
  by `->state()` from a model accessor has no database column, so `->sortable()` alone produces
  a control that reorders by nothing. Pass the expression explicitly —
  `->sortable(query: fn (Builder $q, string $direction) => $q->orderByRaw("… {$direction}"))`.
  `MeterReadingsTable` does this for `usage_kwh`, and **`total_amount` on the same table is the
  instructive counter-case**: it carried an `orderByRaw` of its own until the column stopped
  being derived, and the plain `->sortable()` it has now is correct precisely because there is a
  column to order by. So the question is never which figure looks computed — it is whether the
  state comes from a column or from an accessor, and that answer changes under you when a
  feature is reshaped. See Listrik kost.
- **Navigation groups are set per resource**, not in the panel provider: `$navigationGroup`
  on each `Resource`, with `$navigationSort` ordering within the group. `Oriflame` is the worked
  example, ordering the screen that is worked in daily first and the ones that are set up then
  consulted after it. `Kost` is a group of one since its rooms and tariffs were folded into the
  reading form, and it keeps the group anyway — a lone ungrouped resource sits above every
  grouped one, which reads as more important rather than as unclassified. A resource with no
  group sits above the grouped ones.
  **Keuangan is the one place that leans on that deliberately.** `TransactionResource` (sort 10)
  and `SourceResource` (sort 15) are both ungrouped, so they sit above every group in the order
  their sorts give — the book that is worked in daily, then the list of wallets it draws on. A
  `Keuangan` group around the pair was rejected: `TransactionResource::$navigationLabel` is
  already `Keuangan`, so the sidebar would read "Keuangan › Keuangan".
- Before deploying run `php artisan filament:optimize` — caches component discovery and Blade
  icons. Without it every request pays a directory scan. Re-run `filament:optimize-clear` after
  editing the panel provider, or the cached component list masks your change.

### Panel CSS and JS

**`resources/css/app.css` does not reach the panel.** Filament serves its own compiled
stylesheet and does not go through the app's Vite build, so a rule written there applies to
nothing — the panel is the whole app, so in practice it applies to no page at all. The same
holds for `resources/js/app.js`. Nothing errors; the rule is simply never loaded.

Three ways to add CSS or JS to the panel, and this project picked the third:

| Route | Cost |
|-------|------|
| `->viteTheme('resources/css/filament/admin/theme.css')` | you now own Filament's CSS build — every upgrade means recompiling the theme, and a skipped `npm run build` ships a panel missing its own styles |
| `FilamentAsset::register([Css::make(...)])` | needs `php artisan filament:assets` to have run. It does, via `post-autoload-dump` → `filament:upgrade` — but a deploy that skips composer scripts drops the file silently, the same trap the gitignored Filament assets have |
| **a render hook returning a Blade partial** | inlined into the response, so there is no build step and no publish step to skip. Costs a few hundred bytes per page and no separate cache entry |

For a handful of rules the render hook is the only one of the three with no silent-failure
mode, which is why both current additions use it. They are registered in `AdminPanelProvider`:

| Hook | Partial | What |
|------|---------|------|
| `PanelsRenderHook::STYLES_AFTER` | `resources/views/filament/panel-styles.blade.php` | horizontal table scrolling on narrow screens |
| `PanelsRenderHook::BODY_END` | `resources/views/filament/lightbox.blade.php` | click-to-zoom image viewer — see Media |

**Pick the hook by what has to be loaded already.** `STYLES_AFTER` renders after Filament's own
stylesheet, so rules there win on source order and need no `!important`; `HEAD_END` renders
*before* it and would lose. `BODY_END` renders after Filament's scripts, so Alpine is booted by
the time markup carrying `x-data` appears. Both live in
`vendor/filament/filament/resources/views/components/layout/base.blade.php`, which every panel
page uses — including `/login` and the other auth screens.

**Filament's dark mode is a `.dark` class on an ancestor, not `prefers-color-scheme`.** Custom
CSS written against the media query ignores the panel's own toggle, so it is right half the
time and looks like a rendering bug the rest.

**Alpine is bundled and booted by Filament**, so a component registered with `Alpine.data()`
races its `alpine:init`. An inline `x-data` object literal has no such ordering to get wrong,
which is what `lightbox.blade.php` uses. Anything binding to elements Livewire re-renders has
to delegate from `document` as well — a listener attached to the elements themselves survives
the first update and vanishes on the second, with nothing in the console.

### Tables on narrow screens

`resources/views/filament/panel-styles.blade.php` makes tables scroll sideways below `lg`.
What it does and does not do is worth knowing before reaching for it again:

- **Filament already sets `overflow-x: auto`** on `.fi-ta-content-ctn`
  (`vendor/filament/tables/resources/css/content.css`), and `.fi-ta-ctn` is a **row** flex
  container — for the side filter panels — whose content child `.fi-ta-main` carries
  `min-w-0 flex-1`. That `min-w-0` is what stops a wide table pushing the layout out; without
  it `.fi-layout`'s `overflow-x: clip` would cut the table off with no scroll at all. So the
  scrolling itself needs no help.
- **What was missing is a floor.** Cells wrap until the table fits the viewport, so frequently
  there is nothing to scroll — the columns just become unreadably narrow. `.fi-ta-table` gets
  `min-width: var(--fi-ta-mobile-min-width, 48rem)`, which turns squeezing back into overflow.
  Override the variable on a page wrapper where a narrower table would do.
- **And an affordance.** Overlay scrollbars stay invisible until a scroll is already under way,
  so nothing says more columns exist off-screen. The bar is given a height and a colour.
- `overscroll-behavior-x: contain` is not decoration: without it a swipe that reaches the end
  of the table becomes the browser's back gesture, and the reader loses the page while trying
  to see the last column.

**One per-table override exists.** `.fi-resource-sales` sets
`--fi-ta-mobile-min-width: 62rem`, because a sale carries three rupiah figures plus the derived
margin where the cash book carries one amount — at 48rem each gets about 6rem and
`Rp 1.500.000` wraps. The selector is Filament's own `fi-resource-{slug}` page class
(`ListRecords::getPageClasses()`), so a per-table floor needs no PHP. That also makes it silent
to break: the slug is derived from the model name, so renaming the resource leaves CSS matching
nothing. `SaleResourceTest::test_the_sales_list_carries_the_class_its_table_floor_is_keyed_on`
asserts the class and the rule against one rendered page. The customer list is left at the
default — four columns visible, and a floor there would add a swipe to a table that fits.

The cash book is the one to watch next. It carries six visible columns since Sumber was added —
Waktu, Jenis, Keterangan, Sumber, Jumlah, Bukti — against the sales list's seven, and is still
on the 48rem default. It gets away with it because only one of its columns is a rupiah figure
that must not wrap, while Keterangan is `->wrap()`ed on purpose and Bukti is thumbnails that
shrink; a seventh column, or a second money column, is the point at which it needs its own
`.fi-resource-transactions` floor.

The complementary lever is `->visibleFrom('lg')` on low-value columns, which shortens the
swipe. Note it is **not** `toggleable()` — a column hidden that way cannot be brought back from
the column-manager button on a narrow screen, so it suits columns that are never read on a
phone rather than ones that are occasionally wanted.
