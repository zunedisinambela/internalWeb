# internalWeb

Internal admin web app. Laravel 13 + Filament v5 panel. PHP 8.3+ (dev runs 8.4).

## Commands

```bash
composer setup       # install, .env, key:generate, migrate, npm install + build
composer dev         # -> php artisan dev (server, queue, logs, vite in one process)
composer test        # config:clear then php artisan test (PHPUnit 12, not Pest)
vendor/bin/pint      # format; no pint.json, so Laravel preset defaults apply
php artisan migrate:fresh --seed   # rebuild sqlite + seed admin user
php artisan schedule:work          # NOT part of `composer dev` — see Monitoring
php artisan monitoring:prune       # apply retention now instead of waiting for 03:00
php artisan exports:prune          # delete finished cash book exports past their link expiry
php artisan storage:link           # NOT part of `composer setup` — see Media
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
  Two models, three collections: `App\Models\Transaction` for receipt images, and
  `App\Models\MeterReading` for meter photographs under a collection per meter figure. All on
  the private `local` disk.
  v11, not v12: spatie backported `illuminate ^13` into the v11 line, while v12 is unreleased
  and requires `php ^8.4` against this project's `^8.3` pin. See Media.
- **filament/spatie-laravel-media-library-plugin v5.7** — the upload field, image column and
  image entry that put medialibrary into the panel. A separate package from Filament, and it
  pins medialibrary to `^11.0`. See Media.
- **barryvdh/laravel-dompdf v3.1** on `dompdf/dompdf` v3 — HTML-to-PDF, facade
  `Barryvdh\DomPDF\Facade\Pdf`. Pure PHP, no headless browser, no system binary. One report,
  on the cash book. v3.1.2 is the first release with `illuminate ^13`. See PDF.
- **maatwebsite/excel v4** on `phpoffice/phpspreadsheet` v5 — spreadsheet import and export,
  facade `Maatwebsite\Excel\Facades\Excel`. One export, on the cash book. **v4, not the
  3.1 line the search results and most tutorials still point at** — see Spreadsheet.
- **The queue is load-bearing for one feature.** `QUEUE_CONNECTION=database`, and the cash book
  export is rendered by `App\Jobs\ExportCashBook` rather than in the request — so a deploy
  without `queue:work` produces no file, no notification and no error. The finished file is
  announced through Filament's database notifications, which is why the panel calls
  `->databaseNotifications()` and a `notifications` table exists. The **cache** store is
  load-bearing there as well — the job is `ShouldBeUnique`, and that lock lives in the cache, so
  `CACHE_STORE` has to be a store shared across processes. See Keuangan.
- **Database is SQLite** (`database/database.sqlite`), gitignored via `database/.gitignore`.
  Tests run against `:memory:` (see `phpunit.xml`), so they never touch the dev database.
- Frontend: Vite 8 + Tailwind 4. Filament ships its own compiled CSS/JS and does not go
  through the app's Vite build.
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
  `transactions_exported`), enum values stored in columns (`income`, `expense` —
  see Keuangan), role names (`super_admin`) and permission names (`Delete:Activity`).
  These are filtered on and asserted in tests. Only the human-readable description is
  translated — `LogRoleChange` and `User::booted()` both map the two separately for exactly
  this reason.
- `/log-viewer`. `opcodesio/log-viewer` ships no language files at all; it is a Vue SPA with
  English baked in. Nothing to translate short of forking it.
- Code, comments and commit messages.

`APP_NAME` is still `Laravel`, so that is what the topbar and browser tab show.

## Access control

Roles are the single source of truth. There is no `is_admin` column — it existed briefly and
was dropped in favour of Shield roles so the two could not disagree.

**Filament panel** — `User::canAccessPanel()` returns `$this->roles()->exists()`. Holding any
role opens the door; Shield policies then decide what is reachable inside. Filament checks this
at login *and* on every request through `Http/Middleware/Authenticate.php`, so removing a
user's last role ends a live session on the next page load. Roleless users get 403, guests are
redirected to login. Since the panel is mounted at the root path, that check now covers the
site rather than a `/admin` subtree: a roleless user is refused at `/` itself, and `/login` is
the only page the app serves anonymously.

**Log viewer** — the `viewLogViewer` gate in `AppServiceProvider` uses the same rule. Keep the
two in step: raw log files expose more than the panel does, so a weaker gate here would be a
way around the stronger one.
`LogViewerAccessTest::test_log_viewer_access_matches_panel_access` asserts they agree.

This gate is not optional: `opcodesio/log-viewer` only locks itself down when `APP_ENV` is
exactly `production` (`AuthorizeLogViewer` middleware checks `App::isProduction()`), so without
it staging and every other environment serve log contents to anonymous visitors.

**Attached files** — `/storage/{path}` is the third read surface, and the only one not guarded
by a role. It carries receipt photographs and meter photographs alike, serving the private disk
on a signed, expiring URL, so within that window the link works for whoever holds it, signed in
or not. That is the weakest of the three gates by design; what it protects and what it does not
are set out under Media.

It carries one thing that is not an upload: a **rendered cash book export** is written to the
same private disk and reached through the same signed link (see Keuangan). That is a heavier
payload than a single receipt — one file is the whole filtered book — which is why its link and
the file itself expire together on `ExportCashBook::RETENTION_HOURS` rather than living as long
as the row that owns them.

That export's link is also the one signed URL in this app that is **written down**. Every other
one is minted per request and dies with the page; this one is baked into `notifications.data`
as part of the action, because the notification has to still work when it is opened tomorrow.
So for its lifetime the row is as good as the file: anyone who can read that table — a database
dump, a backup, a future screen that renders notification payloads — holds a working link
without passing a policy. `RETENTION_HOURS` is what bounds that, and it is the reason the value
belongs in hours rather than days.

**Managing users** — `/users` (`app/Filament/Resources/Users/`) creates accounts, sets
usernames and passwords, and assigns roles. Both identifiers a user signs in with are set
here — see Sign-in identifiers. Since a role is what grants access, this screen is how someone
gets into the panel at all. Note it does not restrict *which* role may be handed out: anyone
who can reach it can grant `super_admin`, including to themselves. That is fine while only
super admins hold `Create:User`, but a future staff role with user-management permissions
would be able to self-promote unless the role select is constrained.

**Permissions** — Shield generated 145 permissions named `Action:Subject` (`ViewAny:Activity`).
`super_admin` holds all of them and short-circuits every check through a `Gate::before` hook
(`filament-shield.super_admin.intercept_gate`). Regenerate after adding a resource or page:

```bash
php artisan shield:generate --all --panel=admin
php artisan shield:seeder --force     # refresh ShieldSeeder from the current database
```

`shield:setup` and `shield:super-admin` both throw `NonInteractiveValidationException` on a
chained prompt when run with `--force` in a non-TTY. Run `shield:install`, `shield:generate`
and `shield:seeder` individually instead.

### Sign-in identifiers

Either the **email address or the username** signs a user in. `App\Filament\Auth\Login`
replaces the panel's login page — `->login(Login::class)` in `AdminPanelProvider` — and the whole
change is two methods:

- `getCredentialsFromFormData()` decides the column. Filament hands whatever it returns to
  `EloquentUserProvider::retrieveByCredentials()`, which turns every key but `password` into a
  where clause — so swapping `email` for `username` needs no custom guard and no custom user
  provider. The keys are **AND-ed**, though, which is why the column has to be chosen *before*
  the query rather than searched across both.
- `throwFailureValidationException()` re-points the failure at `data.login`. The base class
  attaches it to `data.email`, a field this form no longer has, and Livewire raises nothing for a
  message on an unknown key — the screen would reload in silence for a wrong password as much as
  for an unknown account.

**An '@' means email, anything else means username.** The rule is total because `username` is
`NOT NULL` and unique, and unambiguous because `UserForm` validates it `alphaDash` — no username
can contain an '@', so no input matches both readings. `test_a_username_containing_an_at_sign_is_refused_by_the_form`
is what keeps that true; drop the rule and the login page starts guessing.

**Usernames are stored lowercase, email addresses are not.** SQLite compares TEXT with `=` case
sensitively, so `Bendahara` against a stored `bendahara` would be an unknown account — the login
page folds the username branch and leaves the email branch alone, because addresses have always
been matched exactly and folding them here would change who can sign in, in passing. `UserForm`
lowercases on `->live(onBlur: true)` rather than only in `->dehydrateStateUsing()`: `unique()`
validates the raw state, so `Admin` typed against a stored `admin` would pass the check and then
hit the index as a `QueryException`.

The custom page lives in `app/Filament/Auth/` rather than under `app/Filament/Pages/`, so that
nothing about it depends on which base class Filament's auth pages happen to extend. Today that
would be safe either way — `Login` extends `SimplePage`, not `Filament\Pages\Page`, and
`discoverPages()` only registers `Page` subclasses — but `EditProfile` *does* extend `Page` and
has to carry `$isDiscovered = false` because of it. Keeping a replacement auth page out of the
scanned directory answers that question once instead of per page.

Backfilled accounts got a username from the local part of their address
(`2026_08_22_000000_add_username_to_users_table`). The column was added nullable, filled, then
tightened to `NOT NULL` and given its unique index: a NOT NULL column cannot be added to a table
that already holds rows, and the index would have refused the second empty value.

`username` is on the `LogsActivity` allowlist beside `name` and `email` — it is an identifier
somebody signs in with, so a change to it belongs in the same trail.

### Two-factor authentication

Filament v5 ships MFA; no extra package was installed. The panel registers
`AppAuthentication::make()->recoverable()` with `isRequired: false`, so **each user decides for
themselves** — a TOTP app (Google Authenticator, Authy, 1Password), eight one-time recovery
codes, no email involved. `->profile(isSimple: false)` is enabled alongside it, because
`EditProfile` is where the set-up/disable actions render; MFA without the profile page is
unreachable.

Email OTP was rejected rather than skipped. `MAIL_MAILER=log` writes mail to the log file, and
`/log-viewer` is readable by anyone holding a role — so email codes would be a way *around*
two-factor for exactly the population it is meant to slow down. Do not add
`EmailAuthentication` until a real mailer is configured.

**Storage** is two nullable `text` columns on `users`, supplied by Filament's
`InteractsWithAppAuthentication` / `InteractsWithAppAuthenticationRecovery` traits — they merge
the casts and the `$hidden` entries themselves, and compose fine with the `#[Hidden]` attribute
already on the model. `text`, not `string`: eight bcrypt hashes as encrypted JSON overrun 255
characters.

The secret is **encrypted, not hashed** — the server must read it back to derive the current
code. So `APP_KEY` now protects every user's second factor as well as the session cookie, and
losing it means every secret must be reset by hand. Recovery codes *are* hashed
(`AppAuthentication::saveRecoveryCodes` runs `Hash::make` per code). Neither column is in
`#[Fillable]`; both are written by direct assignment.

**Auditing** lives in `User::booted()`, not in `LogsActivity` — its allowlist is
`['name', 'username', 'email']`, and widening it to cover the secret column would write the secret into the
log. The hook watches `wasChanged('app_authentication_secret')` instead, so it records *that*
the column changed and never what it changed to. Three events, deliberately distinct:

| Event | Means |
|-------|-------|
| `two_factor_enabled` | secret went from null to set |
| `two_factor_disabled` | the owner cleared their own, which required a valid code |
| `two_factor_reset` | somebody else cleared it, which required no code at all |

The third is the one worth alerting on: it is a step towards taking an account over. Watching
the column rather than the button means a reset done from tinker is logged the same way.
Recovery-code changes are not audited — codes are rewritten every time one is spent at sign-in.

**Admin reset** is `ResetTwoFactorAction`, mounted on the users table and the user view page,
for someone who lost their device. Rules live on the resource
(`UserResource::canResetTwoFactor()`), not on the button, and it asks for the **admin's own**
password — this path skips the code check the profile page enforces, so it should not be one
click away on an unattended session. It hides itself on your own account: the owner disables
theirs at `/profile` with a code, and an owner who has lost their device cannot reach
`/users` in the first place.

It is gated on **holding the `super_admin` role by name**, and this is the one place in the
panel that checks a role rather than a permission. Two reasons, both deliberate:

- `/users` already sets passwords. Clearing the second factor is what turns that into a
  complete account takeover, so it must not ride along with `Update:User` — a permission a
  future staff role would plausibly hold.
- A Shield permission could not express it anyway. `Gate::before` passes every check for super
  admins, so `can('ResetTwoFactor:User')` answers true for anyone who can reach the screen.

`TwoFactorAuthenticationTest::test_only_super_admins_may_clear_someone_elses_two_factor` builds
a role with `Update:User` and asserts it can edit the user but not clear their second factor.

`TwoFactorAuthenticationTest::test_a_correct_password_alone_does_not_sign_in_a_user_with_two_factor`
is the assertion that matters — without it every other test in that file still passes while
two-factor does nothing.

## Keuangan

The cash book. `/transactions` (`app/Filament/Resources/Transactions/`) records money in
and money out, each row optionally carrying photographs of its receipts. It, Listrik kost and
Oriflame are the three features that exist for their own sake; everything else in this panel is
there to keep them honest.

| Piece | Where |
|-------|-------|
| Model | `App\Models\Transaction` — the first `InteractsWithMedia` model here; see Listrik kost for the second |
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

## Listrik kost

Every room is metered separately, so every room is billed separately. Three screens under one
`Kost` navigation group, and one decision that everything else hangs off.

| Piece | Where |
|-------|-------|
| Room | `App\Models\Room`, `/rooms` (`app/Filament/Resources/Rooms/`) |
| Tariff | `App\Models\ElectricityTariff`, `/electricity-tariffs` |
| Reading | `App\Models\MeterReading`, `/meter-readings` |
| Photos | media collections `MeterReading::PHOTOS_START` / `::PHOTOS_END`, private `local` disk |
| Amounts | `App\Rules\WholeRupiah` on the rate, the same rule the cash book uses |
| Correction | `Resources/MeterReadings/Actions/RefreshRateAction` — the one way a recorded rate moves |

**The rate is copied onto the reading, never joined to.** `meter_readings.rate` is a snapshot of
`electricity_tariffs` taken when the reading is recorded, and it is the load-bearing decision of
the whole feature. A join would make every bill read the *current* rate, so entering a raise in
August would silently reprice July — no row changed, nothing in `activity_log`, and a tenant's
issued bill quietly becoming a different number. Copying it means a tariff change applies to
what is recorded after it, which is what raising a tariff actually means.
`test_a_later_tariff_does_not_change_a_recorded_reading` is the assertion that keeps it;
without it the feature still passes every other test while doing the wrong thing.

**The rate field is hidden on both form screens.** It is not a decision taken at the meter — it is
set once on the tariff screen and copied — so asking for it while recording only invites a typo
into the one figure the tenant is billed by. `MeterReadingForm::showsRate()` is the single rule,
read by the field's `->visible()` and by the section heading, which drops "dan tarif" when the
field is not there.

**The view screen does not show it either.** `MeterReadingInfolist`'s `Tagihan` section carries
the total alone. A rate printed beside the total reads as a sum the reader can recompute, and
the one figure they would reach for to recompute it is today's tariff — the exact
misunderstanding the snapshot exists to prevent. The stored rate is still reachable: it is a
`toggleable` column on the list, hidden by default, and every change to it is on the
`meter_reading` allowlist.

It appears on **one** path: no tariff exists, so there is nothing to copy and `rate` is
`NOT NULL`. Hiding it there would refuse the save with a message naming a field nobody can see.
That is the same escape hatch the warning `Callout` announces, and it is why the field keeps its
`->default()`, its `WholeRupiah` rule and its grouped-input round trip.

Showing the field on the edit screen only — the obvious middle ground — was tried and dropped:
entering a reading is a frequent act and correcting a rate is a rare one, so a field that is
wrong to ask for while recording does not become right to ask for while correcting. `rate` stays
on the `LogsActivity` allowlist regardless; it is the one column here whose value came from
somewhere else, so a fix made from tinker is still audited.

**A rate typed wrong is corrected by a button, not by the field.**
`Resources/MeterReadings/Actions/RefreshRateAction` refills one reading's rate from the tariff
row, on the edit screen only. It is the same escape hatch `RefreshPricesAction` is for a sale,
and it exists for the same reason: the snapshot is not negotiable, so an honest mistake — a
reading entered before the tariff screen was filled in, or one recorded while the tariff itself
carried a typo — needs a way out that is not tinker. The four properties that keep it a
correction rather than the automatic recalculation the snapshot forbids are the ones listed
under Oriflame: asked for, shows what it would move, writes into the open form without saving,
and hides itself when the stored rate already matches.

**It takes the tariff in force at `end_read_at`, not the newest one.** That is the single place
it differs from the sales action, and the difference is forced by the data: product prices are
not versioned, so "the current price" is the only answer there, while tariffs are. A July
reading corrected in August therefore has two candidate rates, and the newest is the wrong one
— copying August's rate onto a July bill is exactly the repricing the snapshot exists to
prevent, arriving through a button instead of through a join.
`test_the_rate_refresh_takes_the_tariff_in_force_when_the_period_closed` is what keeps it.
The date is read from `$livewire->data['end_read_at']` rather than from the row, so a correction
that also moves the closing moment offers the tariff for the date being saved. A reading that
closed before any tariff took effect has nothing to copy, so the button is simply absent.

The confirmation names both rates **and the bill each produces**, which matters more here than
on a sale: the rate field is hidden, so the `Perhitungan` total is the only thing on screen that
moves when the action fires, and it is what the user checks before Simpan. The tariff's `note`
is user text interpolated into an `HtmlString`, so it goes through `e()` — same trap as the
product name in `RefreshPricesAction`.

**`->dehydratedWhenHidden()` is what makes hiding it safe, and its absence is silent.** Filament
does not dehydrate a hidden component: `isDehydrated()` returns false through
`isHiddenAndNotDehydratedWhenHidden()`, and the state path is then *removed* from the payload.
Without the flag the column receives nothing, the save fails on a NOT NULL the form never
mentions, and the snapshot this whole feature rests on is gone.
`test_the_rate_is_copied_even_though_the_field_is_hidden` fills the create form without a rate
and asserts the stored figure, which is the only way that stays caught.

**The edit screen is the other half of that, and the more dangerous one.**
`test_editing_a_reading_does_not_recopy_the_current_tariff` saves an unrelated field on a reading
stored at 1.500 while the tariff screen has moved to 2.000, and asserts the row still reads
1.500. A hidden field that re-copied `currentRate()` on save would reprice an issued bill while
looking like an ordinary edit — the exact failure the snapshot exists to prevent, arriving
through the form instead of through a join.

**Tariffs are versioned, not a settings row.** A single row answers "what is the rate now"; these
rows also answer "what was it in July", which is the question a tenant asks. Raising the price
is a new row, never an edit. `effective_from` is **unique**: two tariffs on one day would make
"which rate is in force" unanswerable and the tiebreak would silently become insertion order.
A row dated ahead is how a raise is scheduled — `ElectricityTariff::current()` ignores it until
the day arrives, which is why the status column is derived from the dates rather than stored.
Nothing has to flip a flag at midnight, which matters because this app runs no scheduler for
anything but retention (see Monitoring) and its absence is silent.

`current()` returns **null** on an empty table and callers have to handle it. Inventing a
default rate would put a made-up number onto a bill. The reading form deals with it by warning
and letting the rate be typed by hand rather than refusing to open: the meter has already been
read by then, and the figure is on a phone screen that will be gone tomorrow.

**Rooms are retired, not deleted.** `meter_readings.room_id` is `restrictOnDelete` — not
`cascade`, which would erase the billing history, and not `nullOnDelete`, because a reading
without a room means nothing. That is different from `user_id`, where an unattributed row is
still a true record. So `rooms.is_active` is the exit, and the rule is enforced twice on
purpose:

- `RoomResource::canDelete()` turns the refusal into a missing button instead of a
  `QueryException`. It lives on the resource, not on the action, because Filament consults the
  resource for the row action *and* for every record inside a bulk delete.
- The foreign key covers tinker, a console command and anything that never asks the resource.
  SQLite enforces it only with the `foreign_keys` pragma on, which Laravel sets by default.

**kWh are whole integers, both figures.** Same reasoning as the rupiah columns under Keuangan:
SQLite has no real `DECIMAL`, and `usage × rate` is what becomes money. Meters here read whole
kWh, so fractions are refused outright rather than rounded silently.

**`usage_kwh` and `total_amount` are derived, never stored.** They are accessors on the model, so
they cannot disagree with the three columns they come from — a stored total would be a fourth
number able to contradict them. The cost is that sorting on them has to be spelled out
(`->sortable(query: …)` with an `orderByRaw`), because there is no column to order by and
letting Filament guess would silently sort on nothing.

Neither is clamped at zero. The form refuses a closing figure below the opening one, so a
negative can only come from a row written outside it — and showing it in red is how that becomes
visible. `max(0, …)` would render the same broken row as a plausible bill of Rp 0.

**A reading is a period with two ends, and each end carries three things**: a figure, the moment
it was read, and its own photographs. `start_kwh` / `start_read_at` / `PHOTOS_START` against
`end_kwh` / `end_read_at` / `PHOTOS_END`. The form lays them out as two sections side by side so
a photograph sits under the number it is evidence for; uploading one against the wrong end takes
a deliberate mistake rather than a careless one.

**Two photo collections, not one holding both.** Which photograph backs which figure is the
whole evidentiary point — a disputed bill is settled by comparing the opening figure against the
photograph taken when the period opened. A single collection could only express that by upload
order, which reordering or deleting one file destroys silently.
`collection_name` on the row is what nothing in the UI can scramble.
`test_a_photo_belongs_to_the_end_it_was_uploaded_against` pins it.

**`end_read_at` is what dates the row**, everywhere: the list sorts on it, the date filter
matches on it, and `previousFor()` orders and scopes by it. A period is placed on the timeline by
where it closes — ordering on `start_read_at` would let a short reading taken inside a long one
come back as the later of the two. It also keeps the prefill from being circular, since
`start_read_at` is what that lookup fills in and so cannot also be what scopes it.

The date filter matches the closing moment **only**, never either end. A period straddling a
month boundary belongs to the month it closed in, which is the month it is billed in; matching
both ends would return one reading under two adjacent filters.

**Both ends of the previous reading are prefilled**, which is what makes them one continuous
meter rather than four unrelated fields: `start_kwh` from the previous `end_kwh`, `start_read_at`
from the previous `end_read_at`. Prefilled, not locked: a replaced meter starts again from zero
and only the person holding the photograph knows that happened.
`MeterReading::previousFor()` is the single query behind it, and `Room::latestReading()`
delegates to it rather than repeating the ordering — `id` is the tiebreak, for the same reason
`CashBook` orders by it.

The moment is prefilled only when there **is** a previous reading. A room being read for the
first time keeps the `now()` default rather than having a required field blanked, which would
read as the form breaking rather than as an empty history.

`previousFor()` takes `$before` and `$excludingId` for the edit screen: without them, reopening
a reading offers that same row as its own predecessor.

**Two refusals, one on each pair.** A closing figure below the opening one (`->gte('start_kwh')`)
is refused with a message naming the replaced-meter case, because a typo and a replaced meter
need different handling and the form cannot tell them apart. A closing moment before the opening
one (`->afterOrEqual('start_read_at')`) is refused because `end_read_at` dates the row — such a
reading would sort into the wrong place forever and be offered as the predecessor of readings
taken before it. `afterOrEqual` rather than `after`: both figures read in one visit is a real
case, and a minute-precision picker could not tell it from a mistake anyway.

**Auditing is split three ways**, the same shape as the cash book and for the same reason — no
single mechanism can see all of it:

| Change | Recorded by |
|--------|-------------|
| `room_id`, `start_kwh`, `start_read_at`, `end_kwh`, `end_read_at`, `rate`, `note` | `LogsActivity`, log name `meter_reading` |
| a room's name, occupant or status | `LogsActivity`, log name `room` |
| a rate set or changed | `LogsActivity`, log name `tariff` |
| a photo removed, from either end | `AppServiceProvider::registerMediaDeletionLogging()`, event `meter_photo_deleted` |
| a photo attached or replaced | **nothing** |

Both photo collections write the **same** event key. Which end lost a photograph is already in
the entry's `collection` property, which the listener writes for every owner — a second event key
would mean remembering two of them to filter for "a meter photograph was removed".

`occupant` is on the room allowlist deliberately: who was in a room when a reading was taken is
exactly what a disputed bill turns on.

**What this feature does not do yet**, each a decision rather than an omission:

- **Nothing reaches the cash book.** A reading does not create a `Transaction`, automatically or
  otherwise. Wiring it up raises questions this does not answer — what happens to the
  transaction when the reading is edited or deleted, and whether a reading means money owed or
  money received. Until those are settled, two independent records are honest and one linked
  pair would be misleading.
- **One rate for every room.** A per-room rate (an AC room costing more) needs a column on
  `rooms` plus a fallback to the global tariff. The snapshot on the reading means adding it
  later changes nothing already recorded.
- **No standing charge and no minimum usage.** `total_amount` is `usage × rate` and nothing
  else. Both are common in kost billing and both would be columns on `electricity_tariffs`, so
  they are cheap to add — but adding them unasked would have put figures on a bill nobody
  specified.
- **No bill to hand the tenant.** The panel shows the total; there is no per-room PDF or
  spreadsheet. `pdf.buku-kas` and `TransactionsExport` are the shapes to copy, and PDF and
  Spreadsheet below record what silently goes wrong in each.

## Oriflame

Direct selling, recorded from the consultant's side. Every product carries two prices — what
the catalogue charges and what the consultant pays — and the whole feature exists to keep the
difference between them readable per sale and per customer. Three screens under one `Oriflame`
navigation group.

The worked example it was built from: Ayu takes products A, B and C. The catalogue prices them
at Rp 200.000 together; they cost the consultant Rp 150.000; the margin is Rp 50.000.

| Piece | Where |
|-------|-------|
| Sale | `App\Models\Sale`, `/sales` (`app/Filament/Resources/Sales/`) |
| Line | `App\Models\SaleItem` — no screen of its own, edited through the sale's repeater |
| Customer | `App\Models\Customer`, `/customers` |
| Product | `App\Models\Product`, `/products` — the catalogue, and the source of both prices |
| Amounts | `App\Filament\Forms\Components\RupiahInput` on `App\Rules\WholeRupiah` |
| Correction | `Resources/Sales/Actions/RefreshPricesAction` — the one way a recorded price moves |

**Both prices are copied onto the line, never joined to.** `sale_items.catalog_price` and
`sale_items.marketing_price` are snapshots of `products` taken when the line is entered, and
this is the load-bearing decision of the feature — the same one `meter_readings.rate` makes,
with a sharper reason. Oriflame issues a new catalogue every month and reprices most of it, so
a join would make every recorded sale read the current figures: entering September's catalogue
would rewrite what Ayu bought in August, with no row changed, nothing in `activity_log`, and a
margin that had been correct becoming a different number. Copying means a new catalogue applies
to what is sold after it, which is what a new catalogue means.
`test_a_later_price_change_does_not_reprice_a_recorded_sale` is the assertion that keeps it;
without it the feature passes every other test while doing exactly the wrong thing.
`test_editing_a_sale_does_not_recopy_the_current_prices` covers the same failure arriving
through the form instead of through a join.

The product relation is still used, and only for two things: the product's name on screen, and
prefilling a *fresh* line. Never for a figure on a saved one.

**The escape hatch is a button, and its shape is the whole point.**
`Resources/Sales/Actions/RefreshPricesAction` refills every line of one sale from its products'
current prices. It exists because the snapshot is not negotiable and yet an honest mistake — a
product entered at the wrong price, a sale recorded before the catalogue was filled in — has to
be correctable. Four properties keep it a correction rather than the automatic recalculation the
snapshot forbids:

| Property | Why |
|----------|-----|
| asked for, never automatic | a price change on the product screen still cannot reach a recorded sale |
| shows every line it would move, both figures, before confirming | "nothing changes" and "four lines change" must not be the same click |
| writes into the open form and **does not save** | Simpan is the user's; the `sale_item` audit entries then come from `LogsActivity` on the ordinary path, exactly as a hand correction would |
| hidden when every price already matches | the button's absence answers "are my prices current?" without opening a modal that says no |

**Those four properties are the pattern, not a detail of this feature.** Every copied figure in
this project needs the same escape hatch, and `Listrik kost`'s `RefreshRateAction` is the second
one built to this shape — so when a third snapshot appears, copy the properties rather than
inventing a third answer. What legitimately varies is *which* figure is offered: prices are not
versioned, so there is one candidate, while tariffs are, so the meter version has to pick by
date. See Listrik kost.

It operates on `$livewire->data`, not on the rows. Writing rows directly would be fewer lines,
skip the form's own validation, and silently discard whatever else was already typed on the
page. It is on the edit screen only and deliberately **not** a bulk action on the list: repricing
several sales at once is the shape of the thing the snapshot exists to prevent, and a bulk
version could not show what it was about to change.

The confirmation body is rendered as HTML and interpolates a product name, which is user input —
so it goes through `e()`. That is the only place in this feature where an unescaped value would
be markup rather than text, and
`test_the_confirmation_lists_what_would_change_and_escapes_the_product_name` pins it.

**Prices are not versioned, unlike `ElectricityTariff`.** That looks inconsistent and is not. A
tariff needs its own history because a bill is recomputed from the rate in force on a date and
there is one rate for everything; a catalogue reprices hundreds of products at once, so
versioning would mean a row per product per month to answer a question the snapshot on each
line already answers. What is genuinely lost is "what did this product cost in July" for a
product nobody sold in July — and `activity_log` records every price change with its causer,
which covers the case that comes up.

**Every total is derived, none is stored.** `SaleItem::$catalog_subtotal`,
`$marketing_subtotal` and `$profit`; `Sale::$catalog_total`, `$marketing_total` and `$profit`;
`Customer::$total_spent` and `$total_profit`. A stored total would be a further number able to
contradict the lines it came from, and nothing would say which was right. Two consequences:

- **Sorting has to be spelled out.** There is no column to order by, and `->sortable()` alone on
  a `->state()` column renders a control that silently reorders by nothing. `Sale::sumOfItems()`
  builds the correlated `SUM` once and all three columns pass it to `->sortable(query: …)` —
  the one place the arithmetic is written a second time, so it is written once.
- **The customer list shows a count, not a margin.** The totals walk a loaded relation, so a
  margin per row would be a query per customer; a `withSum` would be a second copy of the
  arithmetic. The view screen calls `loadMissing('sales.items')` instead.

**`RupiahInput` is new, and it is where the grouped-rupiah trio now lives.** `->live(onBlur)` +
`afterStateUpdated`, `->formatStateUsing()` and `->dehydrateStateUsing()` have to travel
together: drop the last one and the column receives the string `"1.500.000"`, which SQLite's
loose typing casts and stores as **1** — no exception, no validation message, and a price that
reads as a rounding bug months later. Four fields in this feature need it, so it became a class.
`Transaction::$amount` and `MeterReading::$rate` predate it and still spell the trio out inline;
converting them is a separate change to tested financial code.

**Laravel's `->lte()` cannot compare two grouped rupiah fields, and fails quietly.** It picks
its comparison from `is_numeric()`, which answers **true** for `"150.000"` — a valid float
string meaning 150.0 — and **false** for `"1.500.000"`, which has two dots. So one side of the
same comparison is read as a number and the other as a *string length*, with no error either
way. It happens to be right whenever both figures land in the same shape, which is most of the
time while testing. `RupiahInput::notGreaterThan()` compares through `WholeRupiah::toInteger()`
instead, which is the only reading that always matches what the column will receive.
`test_a_marketing_price_above_the_catalogue_price_is_refused` picks its figures so the broken
reading and the correct one disagree.

A marketing price *above* the catalogue price is refused on both the product form and every
sale line — in practice it is the two figures entered the wrong way round. Equal prices are
accepted: selling on at cost earns nothing and is still a real sale. Below that, the accessors
are **not** clamped, for the reason `MeterReading::$usage_kwh` is not: a negative margin can
only come from a row written outside the form, and rendering it in red is how that becomes
visible. `max(0, …)` would render the same broken row as a plausible sale earning nothing.

**Customers and products are retired, not deleted.** `sales.customer_id` and
`sale_items.product_id` are both `restrictOnDelete`, so `is_active` is the exit on each. The
rule is enforced twice on purpose, exactly as it is for rooms: `canDelete()` on the resource
turns the refusal into a missing button rather than a `QueryException`, and the foreign key
covers tinker and anything else that never asks the resource. Both stay *selectable* on the
forms while marked `(tidak aktif)` — a filter would leave the edit screen for an old sale with
an empty select and no explanation.

**`sale_items.sale_id` is the one cascade in this project.** A line belongs to its sale and
means nothing without it. The cascade runs in the database and fires no model events, which is
the intended shape rather than a gap: the sale's own `deleted` entry is the record of the act,
and a log holding six extra entries for its lines would bury it.
`test_deleting_a_sale_writes_one_audit_entry` pins the count.

**`SaleItem` has no policy and no Shield permissions**, because it has no resource — Shield
generates per entity, and lines are only ever reached through the sale's repeater. So
`SalePolicy` is what gates them, and that is correct as long as `SaleItem` never gets a screen
of its own. `Gate::getPolicyFor(SaleItem::class)` answers `null`, which is the same shape as the
`Media` gap noted under Gotchas — harmless while nothing authorizes against it directly.

**Auditing is split four ways**, one log name per thing a reader would filter for:

| Change | Recorded by |
|--------|-------------|
| `customer_id`, `occurred_at`, `note` on a sale | `LogsActivity`, log name `sale` |
| a line's product, quantity or either price | `LogsActivity`, log name `sale_item` |
| a customer's name, phone or status | `LogsActivity`, log name `customer` |
| a product's code, name, either price or status | `LogsActivity`, log name `product` |
| lines removed by the cascade | **nothing** — the sale's own entry covers it |

Both price columns are on the `sale_item` and `product` allowlists deliberately: they are the
values copied from somewhere else, so a line whose figures match no current product is only
explicable from the log. `phone` is on the `customer` allowlist for a different reason — a
number changed on the wrong row is how a message about an order reaches the wrong person.

**What this feature does not do yet**, each a decision rather than an omission:

- **Nothing reaches the cash book.** A sale does not create a `Transaction`. Same unanswered
  questions as the meter readings: what happens to the transaction when the sale is edited or
  deleted, and whether a sale is money received now or money owed. Two independent records are
  honest until those are settled.
- **No discount to the customer.** The margin is `catalog − marketing` and nothing else, which
  assumes the customer pays the catalogue price. Giving a friend a break would need a third
  figure per line — what was actually charged — and the margin would then be
  `charged − marketing`. It is a column and a form field, cheap to add; it was left out because
  the example this was built from had no such case and inventing one would have put a figure on
  a record nobody asked for.
- **No payment status.** Nothing records whether the customer has paid. `note` is where "bayar
  minggu depan" goes today. A real answer is a column plus a filter plus a total of outstanding
  money, which is a small feature of its own.
- **No monthly recap, no export.** The list filters by customer, product and date range, and
  the totals are on screen; there is no per-month margin report and no spreadsheet.
  `TransactionsExport` and `App\Reports\CashBook` are the shapes to copy, and Spreadsheet below
  records what silently goes wrong.
- **No stock.** Products are a price list, not an inventory. Nothing tracks what is on hand.

## Monitoring

Three tables, three screens, one retention page. All of it is reachable only with a role, like
the rest of the panel.

| Screen | Table | Written by |
|--------|-------|------------|
| `/visits` | `visits_monitoring` | `App\Http\Middleware\RecordVisit` |
| `/authentications` | `authentications_monitoring` | package listeners on `Login` / `Logout` |
| `/activities` | `activity_log` | `LogsActivity`, `LogRoleChange`, `activity()` calls |
| `/monitoring` | `monitoring_settings` | the retention form itself |

**The package's own routes are disabled, and must stay that way.**
`binafy/laravel-user-monitoring` registers six routes under `/user-monitoring` — three Blade
dashboards and three DELETE endpoints — through `LaravelUserMonitoringRouteServiceProvider`,
with the `web` middleware group and nothing else. No `auth`, no gate, and the controllers never
call `authorize()`: anonymous visitors could read every IP, page and login time, and delete the
rows. That provider loads `routes/user-monitoring.php` instead of its own file whenever the
former exists, so **that file is deliberately empty** and deleting it brings all six back.
`UserMonitoringTest::test_the_packages_own_routes_are_not_registered` asserts they stay gone.

**Config deviations** (`config/user-monitoring.php`, each commented in place):

- `action_monitoring` is off entirely — and note there is **no single `enable` key**: it is off
  because all six event flags (`on_store`, `on_update`, `on_destroy`, `on_read`, `on_restore`,
  `on_replicate`) are `false`. Flipping one turns the whole feature on for that event, so the
  "off" here is six decisions rather than one switch. activitylog already records model changes
  with a per-column diff, a causer and a subject; this package's version stores only a table
  name. No model uses the `Actionable` trait. Never enable `on_read` — it hooks the `retrieved`
  event, so one `/users` page writes a row per listed user.
- `authentication_monitoring.delete_user_record_when_user_delete` is `false`. The default
  `true` makes `user_id` cascade, so deleting an account at `/users` erases its entire
  sign-in history — exactly what you would want to read after removing a suspicious account.
  `false` gives `nullOnDelete()`, matching the visits table. Only read at migration time.
- `visit_monitoring.delete_days` stays `0`. That is the full key path and the only place it
  exists — there is no top-level `delete_days`, and none under the other two sections, so
  `config('user-monitoring.delete_days')` answers `null` and reads like the setting is absent.
  Retention is not configured here (see below), and `0` also keeps the package's own
  `laravel-user-monitoring:remove-visit-monitoring-records` inert — it refuses to run at `0`.
  Two commands pruning the same table from different cutoffs would be a mess.

**What counts as a visit** is `App\Monitoring\PageViewsOnly`, wired through
`visit_monitoring.conditions`. It rejects non-GET requests, anything carrying `X-Livewire`,
anything expecting JSON, and prefetches. Matching on headers rather than paths is deliberate:
Livewire's URL prefix is obfuscated and derived from the app key, so `except_pages` cannot
catch it. Without this the table becomes a keystroke log — every Filament table sort and search
keystroke is its own request.

**`RecordVisit` wraps the package middleware** so a failed insert cannot 500 the app. The
package writes its row inline in the pipeline, so a missing table or a locked database would
otherwise break every page. It calls `parent::handle($request, fn () => null)` inside a try
block and runs the real pipeline afterwards — wrapping `parent::handle($request, $next)`
directly would catch application exceptions and run the pipeline twice. Failures are
`report()`ed, not swallowed.

`RecordVisit` is registered **twice**: in the `web` group in `bootstrap/app.php` and in
`AdminPanelProvider::middleware()`. The panel does not use the `web` group, so listing it in
only one place silently misses whatever the other stack serves.

The split is lopsided now that the panel owns the root path. Panel registration covers every
screen in the app; the `web` group is down to **`/log-viewer` alone** — `/up` is registered
with no middleware group at all, and `routes/web.php` defines nothing. That makes the `web`
half the easy one to delete by accident, and `/log-viewer` is the surface where that matters
most: it serves raw log contents, so a read there is exactly what the visits table should
hold. `UserMonitoringTest::test_a_page_view_outside_the_panel_is_recorded` is the one test
standing on it — every other visit test now goes through the panel stack and would stay green.

### Retention

`delete_days` cannot live in config, because a screen cannot write to a config file:
`config:cache` compiles config into a single PHP file at deploy time, and generating PHP from
user input is how a settings page becomes remote code execution. So the cutoffs live in the
`monitoring_settings` table (one row, read via `MonitoringSetting::current()`), are edited at
`/monitoring`, and are applied by `App\Console\Commands\PruneMonitoring`.

Null means keep forever. Activity log retention is blank by default on purpose — it holds the
record of deletions made on the other two screens.

**`notifications` is not on this screen and is not pruned.** It is not monitoring data — nobody
is audited by it — so putting it under a retention meant for visits and sign-ins would be the
wrong shape. What that costs is real, though: every export leaves a row behind forever, and each
one carries a signed URL that stopped working after `ExportCashBook::RETENTION_HOURS` (see
Access control). Filament's bell marks them read but never removes them, and
`Illuminate\Notifications\DatabaseNotification` is a plain model — it does not use `Prunable`
and defines no `prunable()` scope, so `model:prune` finds nothing to do. Cleaning it means
either a subclass carrying that trait or a few lines in `PruneExports`, which already runs
hourly and already knows the retention. Left out rather than decided against.

**Nothing runs the schedule on its own.** `php artisan dev` starts `serve`, `queue:listen`,
`pail` and `vite` — no scheduler. Without `php artisan schedule:work` locally, or the usual
once-a-minute `schedule:run` cron on a server, retention is saved but never applied. Two
commands are scheduled now, and they fail differently: `monitoring:prune` daily at 03:00, whose
absence the settings page makes visible through `last_pruned_at`, and `exports:prune` hourly,
whose absence is silent — exports keep working and their files simply never go. The
settings page shows `last_pruned_at` for exactly this reason, and stamps it on every run
including one that had nothing to do — otherwise a working scheduler with an empty table looks
identical to no scheduler at all. There is a **Run now** action for applying it by hand.

`config/activitylog.php`'s `clean_after_days` is **not in use**; the package's
`activitylog:clean` is not scheduled. Running it by hand deletes on that number and bypasses
everything below.

### Deleting monitoring data, and the trail it leaves

All three screens allow delete (row and bulk) and refuse create and edit. Who may delete comes
from the Shield policies, not from hardcoded `canDelete()` overrides — that is why those
overrides were removed rather than flipped to `true`.

Each tier's deletions are recorded one tier up, so no screen can erase the evidence of its own
use:

| Deleting from | Recorded in | By |
|---------------|-------------|-----|
| `/visits` | `activity_log`, event `visit_deleted` | `VisitMonitoring::booted()` |
| `/authentications` | `activity_log`, event `sign_in_deleted` | `AuthenticationMonitoring::booted()` |
| `/activities` | the log file, at `/log-viewer` | `AppServiceProvider::registerActivityDeletionLogging()` |

The chain ends at the log file because it is the one surface the panel cannot write to.
Recording activity-log deletions back into the activity log would be circular.

Two things keep this from quietly breaking:

- Every `DeleteBulkAction` is pinned with `->fetchSelectedRecords()`. With it off Filament
  deletes through a single query, which fires **no model events** — bulk removals would vanish
  untraced while single ones stayed audited. Tests assert deleting 2 rows produces 2 entries.
- `PruneMonitoring` deliberately uses a query builder delete (no events) and writes **one**
  summary entry instead. Per-row auditing of a year's traffic would drown the log it protects.
  When it expires activity entries it also writes a `Log::warning`, since its own summary entry
  will eventually fall inside a later window.

The remaining blind spot: `truncate()` or `Model::query()->delete()` from tinker fires no
events and leaves nothing behind. Tests cover the UI paths, not that one.

## Media

`spatie/laravel-medialibrary` attaches files to Eloquent models via the `media` table, and
resizes them through `spatie/image` v3, which arrives as its own dependency. That is the only
image stack here — `intervention/image` was installed alongside it briefly and removed again,
since medialibrary already carries everything needed to manipulate an image.

`env('IMAGE_DRIVER')` belongs to `config/media-library.php` and takes the short string `gd` or
`imagick`. Should a second image package ever be added, give it its own env key: Intervention's
published config claims this exact one but expects a fully-qualified driver class instead, so
sharing it means setting the variable breaks whichever package did not get the format it
wanted — while the package that *appears* broken is not the one whose setting changed.

**Two models use it, across three collections**: `App\Models\Transaction` through `receipts`
(see Keuangan) and `App\Models\MeterReading` through `meter-photos-start` and `meter-photos-end`
(see Listrik kost) — a collection per meter figure, so a photograph says for itself which number
it is evidence for. Transaction settled the
disk question this section used to leave open, and MeterReading followed it without reopening
it — the answer is binding on whatever attaches files next: **the private `local` disk, not
`public`**.
Moving files between disks later means rewriting the `disk` column on every row *and*
relocating the files, so it is the medialibrary equivalent of the timezone decision under
Locale and timezone. A new collection should say `->useDisk('local')` unless there is a
specific reason its contents are safe to publish by URL — see the paragraph on the `public`
disk below for what that decision actually costs.

**`->useDisk()` is only the second of three places the disk is decided, and the fall-throughs
disagree.** Which one applies depends on how the file was attached:

| Attached by | Resolution order |
|-------------|------------------|
| a Filament field | `->disk()` on the field → registered collection's `useDisk()` → `config('filament.default_filesystem_disk')` |
| `addMedia()->toMediaCollection()` in code | the explicit `$diskName` argument → registered collection's `useDisk()` → `config('media-library.disk_name')` |

Those two last resorts are **not** the same disk here. `filament.default_filesystem_disk`
follows `FILESYSTEM_DISK`, which is `local`; `media-library.disk_name` is the package default,
which is `public`. So a collection name that matches no registered collection — a typo, or a
collection nobody declared — skips `useDisk()` entirely and lands on `public` when written from
app code, while the same typo written through a Filament field lands on `local`. Neither raises
anything: the upload succeeds, the row is written, and only the `disk` column says where it
went.

`->visibility('private')` closes the Filament half of that: the plugin's `getDiskName()`
rewrites a resolved `public` back to `local` whenever the component is marked private, at both
fall-through steps. So the flag is not only about signed URLs — it also steers the disk when
the collection lookup misses. There is no equivalent on the `addMedia()` path, which is why
code attaching files outside a form should name the disk itself rather than trust the
collection to be found.

**The trait is safe to add to `User`** despite the `User::booted()` gotcha. `InteractsWithMedia`
registers its hooks from `bootInteractsWithMedia()`, which Eloquent calls *in addition to*
`booted()` rather than instead of it. The one-`booted()`-per-class rule does not apply to trait
boot methods.

**The `public` disk is unauthenticated.** `disk_name` defaults to `public`, which resolves to
`storage/app/public` served through the `public/storage` symlink. Files there are fetched
straight off the filesystem by URL: no role check, no Shield policy, no `activity_log` entry.
That is the same shape of hole as an ungated `/log-viewer` — a read surface that sidesteps the
access control every other screen enforces. Everything in this panel is otherwise role-gated,
so anything more sensitive than an avatar belongs on a private disk behind a controller that
calls `authorize()` and streams via `Storage::disk(...)->response()`.

**The private disk is served by Laravel, not by the web server.** `config/filesystems.php` sets
`serve => true` on `local` and gives it no `visibility` key, so Laravel registers a
`/storage/{path}` route for it and `ServeFile` treats it as private: every request without a
valid relative signature is refused (403 outside production, 404 in it) *before* the file is
looked for. `Storage::disk('local')->temporaryUrl($path, $expiry)` is what mints an acceptable
link.

**Both local disks answer at `/storage`, and only one of them is a route.** `local` has no `url`
key so its served route defaults to `/storage/{path}`; `public` has `url` = `APP_URL/storage`,
which parses to the same path. Today that does not collide, because only `local` sets
`serve => true` — but it has two consequences:

- The `public/storage` symlink is checked first by the web server, for both `artisan serve` and
  a normal `try_files`. A file present under `storage/app/public` therefore shadows a private
  file at the same relative path, and is delivered with no signature check at all. Nothing hits
  this today because `receipts` is the only collection and it is entirely on `local`.
- Setting `serve => true` on `public` throws `InvalidArgumentException` at boot —
  *"The [public] disk conflicts with the [local] disk at [/storage]"* — from
  `FilesystemServiceProvider::serveFiles()`. Give one of them a distinct `url` first.

Filament asks for that signed link only when a component is marked `->visibility('private')`.
Without it `SpatieMediaLibraryFileUpload`, `SpatieMediaLibraryImageColumn` and
`SpatieMediaLibraryImageEntry` each fall through to a plain `getUrl()`, the private disk refuses
it, and the screen renders a broken image with nothing in the log. All three call sites in
`Resources/Transactions` set it.

A signed URL is **not** a policy check: for its lifetime it works for whoever holds it. That is
a deliberate step up from the public disk, not the end of the road. The full answer is still a
controller that calls `authorize()` and streams via `Storage::disk(...)->response()`, and it
becomes worth building the moment these files are linked to from outside the panel.

**`php artisan storage:link` is not in `composer setup`.** The symlink is gitignored
(`/public/storage`), so a fresh clone has no `public/storage` and every `public`-disk media URL
404s while uploads themselves succeed. Same failure shape as the missing scheduler under
Monitoring: the write path works, so nothing looks broken until someone tries to read. Nothing
currently depends on it — `receipts` is on `local`, which needs no symlink — so this only bites
the first collection that opts back into `public`.

**Conversions run on the queue by default.** `QUEUE_CONNECTION=database`, so thumbnails are
generated by `PerformConversionsJob` rather than inline. `php artisan dev` runs `queue:listen`,
so this works locally. A deploy without a queue worker uploads originals fine and never produces
a single conversion — the log stays clean because nothing failed, the jobs simply sit in the
table. `Transaction`'s `thumb` conversion opts out with `->nonQueued()` for exactly that reason;
weigh the same trade-off for any conversion small enough to do in the request.

**`Media` is a vendor-namespace model**, so if it ever gets a panel screen, `MediaPolicy` must
be registered by hand in `AppServiceProvider::registerVendorModelPolicies()`. Unregistered, the
policy is silently ignored and every permission check on it passes — the `Activity` trap, again.
Subclassing into `App\Models` the way `VisitMonitoring` does is the other way out.

**Files are deleted on `deleting`, not `deleted`**, from `bootInteractsWithMedia()`. Three
consequences worth knowing before relying on it:

- A soft-deleted model **keeps** its files; they go only on `forceDelete()`.
- `$model->deletePreservingMedia()->delete()` skips the cleanup deliberately.
- It is a model event, so it does not fire for a query-builder delete. This is the same blind
  spot the Monitoring section closes with `->fetchSelectedRecords()` on every bulk action — but
  here the cost is orphaned *files* on disk, which no later query can find to clean up.

**Only deletions are audited, and only for the models on the map.**
`AppServiceProvider::registerMediaDeletionLogging()` hooks `Media::deleted` once and looks the
owner up in `AppServiceProvider::AUDITED_MEDIA_OWNERS`, which currently holds `Transaction`
(log `transaction`, event `receipt_deleted`) and `MeterReading` (log `meter_reading`, event
`meter_photo_deleted`). Attaching and replacing a file are **not** recorded, and a model absent
from the map is not recorded at all. Media is a relation, so `LogsActivity` cannot see it — this
is the same split `LogRoleChange` makes for roles.

**Add to the map, never a second listener.** `Media::deleted` fires for every model in the app,
so a listener per owner means a full check per deletion for each one, and as many places for
the shape of the entry to drift. The log name and event key differ per owner deliberately:
filtering the log for "a receipt was removed from the cash book" must not also return meter
photographs.

**The map is keyed by owner, not by collection**, and that is the right granularity. `MeterReading`
has two collections and both write `meter_photo_deleted`; which end lost its photograph is in the
entry's `collection` property, which the listener writes for every owner already. Splitting the
key per collection would mean two event keys to remember for one question.

Adding `LogsActivity` to the media model itself would need the usual explicit allowlist —
`file_name` and `collection_name`, never `custom_properties`, which is a free-form JSON bag
whose contents nobody controls centrally.

**Filament integration is `filament/spatie-laravel-media-library-plugin` v5.7.6**, a separate
package from Filament itself, and the source of `SpatieMediaLibraryFileUpload`,
`SpatieMediaLibraryImageColumn` and `SpatieMediaLibraryImageEntry`. Note it constrains
`spatie/laravel-medialibrary` to `^11.0` — a second reason the v11 line was the right choice
over the unreleased v12.

## PDF

`barryvdh/laravel-dompdf`, rendering through `dompdf/dompdf` v3 in pure PHP — no headless
Chrome, no `wkhtmltopdf`, nothing to install on the host. The cost is a renderer with roughly
CSS 2.1 support: no flexbox, no grid, no modern layout. Build PDF Blade views with tables and
floats, not with anything borrowed from the panel's Tailwind.

One report exists: `resources/views/pdf/buku-kas.blade.php`, asked for from the cash book list
by `ExportTransactionsAction::pdf()`. No route serves a PDF, and since the render moved onto the
queue no *response* carries one either: `App\Jobs\ExportCashBook` writes the bytes to the
private disk and the user is handed a signed link. See Keuangan.

```php
use Barryvdh\DomPDF\Facade\Pdf;

Pdf::loadView('pdf.laporan', ['rows' => $rows])
    ->setPaper('a4', 'portrait')
    ->download('laporan.pdf');   // or ->stream(), or ->output() for the raw bytes
```

**`->download()` does not work from a Filament action, and the error says nothing.** It returns
a plain `Illuminate\Http\Response`, while Livewire's `SupportFileDownloads` only intercepts a
`BinaryFileResponse` or a `StreamedResponse` — anything else falls through to the ordinary
return path, where Livewire tries to JSON-encode the response object and throws
**`Type is not supported`**. Hand back `response()->streamDownload(...)` instead. (Laravel-Excel
is unaffected: its `download()` already returns a `BinaryFileResponse`.)

The cash book no longer takes that route — it renders in a job and stores the bytes with
`->output()`, so no response object is involved at all. The trap is recorded here anyway,
because the next report written will start as a synchronous action and hit it.

**dompdf has no `pages` counter, so `counter(pages)` silently prints `0`.** Nothing in its
`src/` refers to one; only `counter(page)` resolves. The usual workaround, `$PAGE_COUNT`, lives
inside `<script type="text/php">` and therefore needs `enable_php` — see the table below for
why that is not worth a page number. The supported route is a canvas call, which needs neither:

```php
$pdf->render();                                  // sets barryvdh's `rendered` flag
$canvas = $pdf->getDomPDF()->getCanvas();
$canvas->page_text($x, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 8);
$pdf->output();                                  // does not re-render
```

`page_text()` runs the substitution once per page, so it is also how a footer gets onto every
page. `$font` is a font *file*, not a family — resolve it with
`$dompdf->getFontMetrics()->getFont('sans-serif')`. Rendering first and then reaching for the
canvas is barryvdh's own idiom; `PDF::setEncryption()` does exactly this.

`<thead>` does repeat across pages without any help, so a long table stays readable.

`dompdf.options.default_paper_size` is already `a4` and should stay that way. Note the full key
path: it lives *inside* the `options` array, so `config('dompdf.default_paper_size')` answers
`null` and reads like the setting is missing. That value comes from
barryvdh's published config, not from dompdf — `Dompdf\Options::$defaultPaperSize` is `letter`.
Anything that bypasses the Laravel config and drives `Dompdf` directly gets US Letter.

**`storage/fonts` must exist or custom fonts crash the request.** dompdf does *not* create
`font_dir`, and the failure is a `TypeError` out of `php-font-lib`, not a graceful fallback:

```
fopen(storage/fonts/..._normal_<hash>.ufm): Failed to open stream: No such file or directory
TypeError: fwrite(): Argument #1 ($stream) must be of type resource, false given
```

The directory is committed with a `.gitignore` (`*` / `!.gitignore`) the way
`storage/framework/cache` is, so a clone gets it. dompdf writes converted `.ufm` metrics and a
copy of every embedded font there at render time — it is a cache, not an asset, and must stay
out of the repo and writable by the web user. **Base 14 fonts (Helvetica, Times, Courier) need
none of this**, so a report that does not declare `@font-face` renders fine even with the
directory gone. That is exactly why the crash shows up late, on the first PDF that wants a
brand font.

They do still *write* there when the directory exists, which makes that easy to misread: one
base-14 render drops `Times-Roman.afm.json`, `Helvetica.afm.json` and friends into
`storage/fonts`, so finding those files is not evidence that base 14 depends on the directory.
Removing it and rendering the same documents produces byte-identical PDFs — 1137, 1135 and 1133
bytes for Times, Helvetica and Courier either way — and the cache write is skipped in silence.
Only an embedded font turns a missing directory into the `TypeError` above.

**The generic families map to base 14, not to the DejaVu fonts dompdf ships.**
`lib/fonts/installed-fonts.dist.json` resolves `sans-serif` to **Helvetica** and `serif` to
**Times-Roman**; `DejaVuSans` is reachable only by naming `DejaVu Sans` explicitly. So a view
asking for `sans-serif` embeds nothing and stays tiny — `pdf.buku-kas` renders four rows in
about 2.8 KB — while the same view naming DejaVu would embed hundreds of kilobytes per weight
and start depending on `storage/fonts`. Helvetica's WinAnsi covers `–`, `—` and `·`, which is
enough for an Indonesian document; reach for DejaVu only when the text genuinely leaves that
range. `config/dompdf.php` sets `default_font` to `serif`, so an unstyled view gets Times.

**`show_warnings` is `false`, so font and asset failures are silent.** A `@font-face` that
cannot be loaded produces a valid PDF in a fallback face and no error anywhere. The size
difference is the tell — the same one-line document rendered 1.1 KB with the font silently
dropped and 259 KB with it embedded. Flip `show_warnings` to `true` while developing a PDF
view, then put it back; it converts those into thrown exceptions.

**`chroot` is `base_path()`, and `file://` is in `allowed_protocols`.** Together that means
anything dompdf is asked to load can reach *any file in the project* — `.env` included. That
matters more here than in most apps: per Access control, `APP_KEY` decrypts every user's
two-factor secret, so `.env` is not merely configuration.

The exposure is only reachable through content dompdf parses, so it is fine while PDF views are
Blade templates you wrote. It stops being fine the moment user-controlled text is interpolated
into one — a note field, a filename, a display name — since that text is parsed as HTML/CSS.
Escape it (`{{ }}`, never `{!! !!}`), and if a PDF ever renders anything user-supplied, narrow
`chroot` to the directories that genuinely hold assets, or drop `file://` from
`allowed_protocols` entirely.

Two related settings, both verified at their shipped values:

| Option | Value | Note |
|--------|-------|------|
| `enable_php` | `false` | correct — `true` executes `<script type="text/php">` with full app privileges |
| `enable_remote` | `false` | correct — blocks SSRF via `<img src="http://…">`; also means remote CSS and images will not load |
| `enable_javascript` | `true` | vendor default, not a decision. This is JS embedded *in the PDF* for the viewer to run, useless for reports; `false` is the safer setting |

**A PDF is a read surface, so it is gated and audited like any other.** Generating one fires no
model event, so nothing records it unless the caller does. The cash book report is the worked
example: authorization on the resource (`TransactionResource::canExport()`) and an `activity()`
entry under the `monitoring` log name — the *same* entry the spreadsheet writes, distinguished
by a `format` property rather than a second event key. A PDF of records the caller cannot open
in the panel would be a way around the policy that guards the screen.

**Interpolating user text is where `chroot` stops being theoretical.** `pdf.buku-kas` renders a
description someone typed into a form, so every value in it goes through `{{ }}`.
`test_a_description_is_escaped_rather_than_parsed_as_markup` asserts the template never
switches to `{!! !!}` — a single such change would hand a user's markup to a parser that can
read `.env`.

## Spreadsheet

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

## Gotchas

**Uploads keep their EXIF.** Medialibrary stores the original file untouched, so GPS
coordinates and device serials from a phone camera survive into whatever disk it lands on — and
on the `public` disk that metadata is fetchable by URL along with the image. Conversions are
re-encoded and lose most of it, but the original is what `getUrl()` returns by default. The
receipt and meter-photo screens work around this rather than solve it: both render the `thumb`
conversion everywhere, so the original is only reached by a deliberate signed request. Nothing
strips the original, and stripping it would be a decision about altering what a user uploaded.

That matters more for meter photographs than for receipts. A receipt is photographed wherever
it happens to be; a meter is bolted to the building, so its EXIF coordinates are the address of
a property with tenants in it.

**User-typed text reaches three kinds of surface, and each escapes differently.** A product
name, a transaction description, a room's occupant and a sale note are all free text somebody
typed into a form. Verified against the vendor source rather than assumed, because the three do
not behave alike:

| Surface | What actually happens |
|---------|-----------------------|
| a Blade view | `{{ }}` escapes, `{!! !!}` does not. In a **PDF** view the stakes are higher than XSS: dompdf's `chroot` is `base_path()` and `file://` is in `allowed_protocols`, so parsed markup can reach `.env` — and `APP_KEY` decrypts every user's two-factor secret. See PDF. |
| a Filament description or heading — `->modalDescription()`, `Callout`, `Section`, empty state | all rendered `{{ $description }}`. A plain **`string` is escaped**; an **`Htmlable` is not**, because Laravel's `e()` passes `Htmlable` straight through to `toHtml()`. |
| `Notification::title()` / `::body()` | neither escaped nor raw — both go through `str(...)->sanitizeHtml()`. Scripts and event attributes are stripped, but **markup is still interpreted**, so a product name containing `<b>` renders bold rather than showing the tag. |

So the trap is narrow and specific: reaching for `HtmlString` to get a list or a line break in a
description, and carrying a user value in with it. Returning a plain string needs nothing.
`RefreshPricesAction` is the worked example — it builds a `<ul>` of product names and runs each
through `e()` — and `test_the_confirmation_lists_what_would_change_and_escapes_the_product_name`
is what keeps that from being quietly dropped. `RefreshRateAction` hit the same trap from a
different direction: its confirmation carries a *tariff note*, which is user text arriving from a
table nobody thinks of as user input. Both have a test asserting the escape, because the escape
is one call that reviews cleanly whether it is there or not.

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

`App\Models\SaleItem` answers `null` too, for an unrelated reason: Shield generates per
*entity*, and a model with no resource gets no permissions and no policy. Lines are only ever
reached through the sale's repeater, so `SalePolicy` is what gates them — correct exactly as
long as `SaleItem` never gets a screen of its own. Giving it one means generating its policy in
the same pass, or every check against it passes silently. See Oriflame.

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
| `Transaction`, `MeterReading`, `ElectricityTariff`, `Sale` | stamp the author from the session |
| `VisitMonitoring`, `AuthenticationMonitoring` | write the `visit_deleted` / `sign_in_deleted` audit entries |

Trait boot methods are exempt: `bootInteractsWithMedia()` runs *in addition to*
`Transaction::booted()`, which is why the two coexist there.

**`permission.events_enabled` is set to `true` on purpose.** It ships as `false`. Role grants
and revocations are audited through those events, so turning it off silently removes the
privilege-escalation trail.

**Never `Hash::make()` a password in seeders or factories.** `User::casts()` sets
`'password' => 'hashed'`, so Eloquent hashes on assign. Hashing first produces a double hash
and login silently fails.

**Seeders do not write audit entries.** `AdminUserSeeder` uses `WithoutModelEvents`, and
`LogsActivity` hooks model events. Granting admin through a seeder therefore leaves no trace
in `activity_log`.

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
The account is `admin@admin.com` / `admin`, and its username is `admin` — either identifier
signs it in (see Sign-in identifiers). Deliberately weak and local-only — there is no
environment guard on the seeder, so do not run `--seed` against a production database.

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

**Nine models carry the trait**, each with its own log name and its own explicit allowlist:

| Model | Log name | Feature |
|-------|----------|---------|
| `User` | `user` | Access control |
| `Transaction` | `transaction` | Keuangan |
| `Room`, `ElectricityTariff`, `MeterReading` | `room`, `tariff`, `meter_reading` | Listrik kost |
| `Customer`, `Product`, `Sale`, `SaleItem` | `customer`, `product`, `sale`, `sale_item` | Oriflame |

Three of them pair the trait with a separate listener, because the thing worth auditing is not a
column and `LogsActivity` cannot see it: roles on `User` (a pivot table), receipts on
`Transaction` and photographs on `MeterReading` (a relation). `Sale` splits for a different
reason — its lines *are* rows with their own trait, kept under their own log name so "who
changed this sale's customer or date" stays readable without every line edit in between.

When adding the trait to another model, keep the same shape: name the log, list attributes
explicitly, and add a test asserting nothing outside the allowlist reaches `attribute_changes`.
`UserActivityLoggingTest`, `TransactionResourceTest` and
`SaleResourceTest::test_nothing_outside_the_allowlist_is_logged` each have one to copy.

**The Kost models are the gap.** `Room`, `ElectricityTariff` and `MeterReading` assert *that*
their allowlisted columns are logged and never that unlisted ones are not, so widening one of
those `logOnly()` calls — or adding a column that a future refactor sweeps into it — would not
fail anything. None of the three holds a secret today, which is why it has gone unnoticed; the
test to copy is three assertions long.

The UI is `app/Filament/Resources/Activities/`. `canCreate()` and `canEdit()` return `false`,
so Filament never registers create or edit routes — an editable audit entry is worse than a
deleted one, because it still reads as true. Keep it that way. Deletion **is** allowed, gated
by `Delete:Activity` / `DeleteAny:Activity` and logged to the file log; see Monitoring for the
full chain. The query eager-loads `causer` and `subject` because both are morphs and cannot be
joined.

Log names in use: `user` (model changes including either sign-in identifier, role grants,
two-factor changes), `transaction`
(cash book rows and receipt deletions — see Keuangan), `room`, `tariff` and `meter_reading`
(the electricity feature and its photo deletions — see Listrik kost), `sale`, `sale_item`,
`customer` and `product` (the Oriflame feature — see Oriflame), and `monitoring`
(deletions, prunes, and both cash book exports — a read that leaves the panel is recorded
here rather than under `transaction`, because it is an operation on the book rather than a
change to it; the export entry is written by the queued job rather than by the request, so its
causer is passed explicitly).
Descriptions are Indonesian; `event` keys are not — see Locale and timezone.

## Filament conventions

- Resources, Pages and Widgets are auto-discovered from `app/Filament/{Resources,Pages,Widgets}`.
  Creating a class there is enough; no manual registration. `Filament/Widgets` does not currently
  exist — the panel provider still points `discoverWidgets()` at it, which is harmless, but see
  the dashboard note below before creating it.
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
  events, the validation and the audit entries on the normal path. Repeater items live under
  `data.<field>.<uuid>.<name>`, keyed by uuid, so the path cannot be written out in advance;
  iterate the array instead. Write values in the shape the field *holds* (a `RupiahInput` holds
  a grouped string, not an integer). `RefreshPricesAction` is the worked example, and
  `test_refreshing_prices_fills_the_form_without_saving` is what keeps it from quietly becoming
  a direct write. `RefreshRateAction` is the same shape onto a single scalar field
  (`data['rate']`) instead of a repeater, and it also *reads* from `$livewire->data` — the
  closing moment it picks a tariff by is whatever the form currently holds, not what the row
  holds, so a correction that moves the date and the rate together stays consistent.
- **A field hidden from the form is still reachable from `$livewire->data`**, and that is what
  makes a hidden column correctable at all. `MeterReading::$rate` is hidden on both form screens
  yet `->dehydratedWhenHidden()`, so an action can write it and the ordinary Simpan commits it.
  The catch is that the user cannot see the field move: something else on screen has to be the
  evidence. `RefreshRateAction` leans on the `Perhitungan` total, which is a `TextEntry` reading
  `$get('rate')` and therefore re-renders with the new figure — and the confirmation names the
  bill before and after rather than only the rate, because the rate alone is not checkable.
- **A grouped rupiah field is `App\Filament\Forms\Components\RupiahInput`.** It assembles the
  `->live(onBlur)` / `->formatStateUsing()` / `->dehydrateStateUsing()` trio that has to travel
  together — losing the last one stores `"1.500.000"` into an INTEGER column, which SQLite
  casts to **1** with no error. Use `->notGreaterThan()` rather than Laravel's `->lte()` to
  compare two of them; `lte` decides how to compare from `is_numeric()`, which reads
  `"150.000"` as a number and `"1.500.000"` as a string length. See Oriflame.
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
  factories over a shared private base — `ExportTransactionsAction::excel()` and `::pdf()`
  differ only in the renderer, and splitting them would duplicate the gate and the audit call.
  **More than one mount point is sufficient reason, not the only one.** An action carrying real
  logic — a built-out confirmation, a state diff, a notification — belongs in its own class from
  the first mount, so the page class stays a list of what is on the page.
  `RefreshPricesAction` and `RefreshRateAction` are each mounted once and are classes for that
  reason; a `getHeaderActions()` holding sixty lines of price arithmetic is where a page stops
  being readable.
- **A media component repeated per collection belongs in a private factory method** on the schema
  or table class, not typed out twice. `MeterReadingForm`, `MeterReadingsTable` and
  `MeterReadingInfolist` each build both of their photo components from one `photos()` helper.
  The reason is the failure mode rather than the line count: the flag that matters most on these,
  `->visibility('private')`, produces a broken image and nothing in the log when it goes missing
  from one copy, so a second copy is a second chance to lose it silently.
- **An action that returns a download must return a `BinaryFileResponse` or a
  `StreamedResponse`.** Livewire's `SupportFileDownloads` intercepts exactly those two; any
  other response object falls through to the ordinary return path and Livewire tries to
  JSON-encode it, throwing **`Type is not supported`** — a message that names neither the
  action nor the response. `Excel::download()` already returns the right type;
  `Pdf::download()` does not, so wrap it in `response()->streamDownload(...)`. See PDF.
  **An action that queues the render returns nothing at all**, which sidesteps this entirely —
  and buys a different obligation: the request has ended before the file exists, so a flash
  message can no longer deliver it. `ExportTransactionsAction` flashes "sedang diproses" and
  `ExportCashBook` sends a database notification carrying a signed link. Dropping the second
  half leaves a job that writes a file nobody is ever told about.
- **A double click is guarded on the job, not on the button.** `->disabled()` after a click, a
  `->requiresConfirmation()`, a spinner — none of them survive a second browser tab, a
  double-submit, or a user who reloads and clicks again, and all of them are client state. The
  server-side answer is `ShouldBeUnique` with a `uniqueId()` that describes the *request*, so a
  genuine repeat is refused and a changed one is not. `ExportCashBook` is the worked example, and
  the sharp edge is that a wrong key fails silently in both directions — too broad and it
  discards legitimate work, too narrow and it guards nothing. See Keuangan.
- **A hidden field is not saved.** `->hidden()` / `->visible(false)` makes `isDehydrated()`
  return false, and the component's state path is stripped from the payload — so a field hidden
  to tidy a form silently stops writing its column. Pair it with `->dehydratedWhenHidden()`
  whenever the value still has to reach the row, and assert the stored value in a test:
  the form shows no error, and the failure surfaces as a NOT NULL violation naming a field the
  user cannot see. `MeterReadingForm`'s rate field is the worked example.
- **A `$set()` onto a date picker has to match that picker's own precision.** A
  `DateTimePicker` configured `->seconds(false)` carries state as `Y-m-d H:i`, so writing
  `Y-m-d H:i:s` into it from an `afterStateUpdated()` puts a shape in the form state that the
  field never produces on its own. It still displays and still saves, so nothing fails — but
  `assertSchemaStateSet()` compares the raw string and every test written against the field's
  natural output disagrees with it. `MeterReadingForm` formats its prefill to match.
- **A column with nothing behind it cannot sort itself.** `TextColumn::make('total_amount')`
  fed by `->state()` from a model accessor has no database column, so `->sortable()` alone
  produces a control that reorders by nothing. Pass the expression explicitly —
  `->sortable(query: fn (Builder $q, string $direction) => $q->orderByRaw("… {$direction}"))`.
  `MeterReadingsTable` does this for both derived columns.
- **Navigation groups are set per resource**, not in the panel provider: `$navigationGroup`
  on each `Resource`, with `$navigationSort` ordering within the group. `Kost` and `Oriflame`
  are the worked examples, and both order the screen that is worked in daily first and the ones
  that are set up then consulted after it. A resource with no group sits above the grouped ones.
- Before deploying run `php artisan filament:optimize` — caches component discovery and Blade
  icons. Without it every request pays a directory scan. Re-run `filament:optimize-clear` after
  editing the panel provider, or the cached component list masks your change.

## Tests

`tests/Feature` covers the security-relevant behaviour; run the suite before changing any of it.
283 tests at the last count.

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
| `TransactionResourceTest` | policy gating, integer rupiah and what a fractional amount costs, grouped input round-trips and an ambiguous one is left alone, `occurred_at` default, receipts stay private and unsigned reads are refused, receipt / cascade / bulk delete auditing |
| `TransactionExportTest` | who may download the book, the two-column ledger and its running balance, chronological order regardless of the table sort, filters carry over, amounts and dates are values rather than text, `0` prints while a blank side stays empty, **both formats queue rather than download** and the job carries the filtered ids and a name stamped at dispatch, the rendered file lands on the private disk and is announced by a database notification, a second click on the same screen queues nothing while a different row set or format still does, the same rows in a different order are one request, an expired file is pruned while a fresh one is kept, both formats audit under one event, the PDF escapes user text and signs a negative balance readably, an empty book still renders |
| `RoomResourceTest` | policy gating, a room with readings cannot be deleted from the resource *or* the database, deactivation keeps its readings, latest-reading ordering and its `id` tiebreak, occupant changes audited, bulk delete audited per row |
| `ElectricityTariffTest` | policy gating, the rate in force is the latest that has started, a scheduled rate stays out until its date, an empty table has no rate, two tariffs cannot share a date, author stamped, grouped input round-trips, rate changes audited |
| `MeterReadingResourceTest` | policy gating, usage and total derived from stored figures, **a later tariff does not change a recorded reading**, the rate field is hidden on both form screens yet still copied onto the row, shown only when there is no tariff, editing does not re-copy the current tariff, **the refresh-rate button fills the form without saving** and only commits through Simpan, takes the tariff in force when the period closed rather than the newest one, hides itself when the rate already matches or no tariff had taken effect, and escapes the tariff note in its confirmation, the form prefills the rate in force and both ends of the previous reading, a room with no history keeps the default opening moment, a closing figure below the opening one is refused, a closing moment before the opening one is refused while an equal one is accepted, author stamped, both reading moments default to now, the create button waits for a room, a photo belongs to the end it was uploaded against, photos stay private and unsigned reads are refused, photo / cascade / bulk delete auditing |
| `SaleResourceTest` | policy gating, the three totals derived from the lines, **a later price change does not reprice a recorded sale**, editing does not re-copy current prices, picking a product copies both prices onto the line, grouped input round-trips, a duplicate product line is refused, a marketing price above the catalogue price is refused, author stamped, the date defaults to now, the create button waits for a customer *and* a product, the cascade removes the lines and writes one entry, line price corrections audited, nothing outside either allowlist is logged, the view screen renders its repeatable entry, **the refresh-prices button fills the form without saving** and only commits through Simpan, hides itself when prices already match, and escapes the product name in its confirmation |
| `ProductResourceTest` | policy gating, the unit margin derived from the two prices, a negative margin reported rather than clamped, grouped input round-trips, a marketing price above the catalogue price is refused while an equal one is accepted, a fractional price is refused, price changes audited, a sold product cannot be deleted from the resource *or* the database, deactivation keeps its lines |
| `CustomerResourceTest` | policy gating, totals summed across every sale, a customer with no sales totals zero, a customer with sales cannot be deleted from the resource *or* the database, deactivation keeps their sales, phone changes audited, bulk delete audited per row |
| `PageViewsOnlyTest` (Unit) | which requests count as a visit |
| `WholeRupiahTest` (Unit) | which amounts are whole rupiah, that untidy grouping is accepted, and that `1500.75` is refused rather than regrouped |

**`phpunit.xml` raises `memory_limit` to 512M**, and that is not decoration. The whole suite runs
in one process, and `TransactionExportTest` builds real `xlsx` files through phpspreadsheet and
zipstream — by far the heaviest thing here. Past roughly two hundred tests it began exhausting
PHP's 128M default, and the failure is a fatal error *inside zipstream* with no assertion
attached: it reads as a broken export rather than as a memory ceiling. Lower it back and the
next test file added rediscovers that the hard way.

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
`CashBook`, which is the source both renderers read. `TransactionExportTest` does all three.

Whatever renders a PDF, verify it by eye once as well: `show_warnings` is `false`, so a
mis-specified font or a missing asset produces a valid document with the problem in it and
nothing in the log. `pdftotext -layout` is enough to check the page structure, the totals and
the page numbering without opening a viewer.

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
