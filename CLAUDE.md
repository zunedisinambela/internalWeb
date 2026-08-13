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
```

## Stack notes

- **Laravel 13**, framework `^13.17`. Several APIs differ from Laravel 10/11 docs — check
  `vendor/laravel/framework` before trusting an older recipe.
- **Filament v5** (`^5.0`) at `/admin`. Panel config lives entirely in
  `app/Providers/Filament/AdminPanelProvider.php`, registered via `bootstrap/providers.php`.
- **spatie/laravel-activitylog v5** — audit trail in the `activity_log` table, browsable at
  `/admin/activities`.
- **opcodesio/log-viewer v3** — log file browser at `/log-viewer`, outside the Filament panel.
- **bezhansalleh/filament-shield v4** on **spatie/laravel-permission v8** — roles and
  permissions, managed at `/admin/shield/roles`.
- **binafy/laravel-user-monitoring v1** — page views and sign-in history, at `/admin/visits`
  and `/admin/authentications`. Installed as a data collector only; its own routes and Blade
  dashboards are disabled. See Monitoring.
- **Database is SQLite** (`database/database.sqlite`), gitignored via `database/.gitignore`.
  Tests run against `:memory:` (see `phpunit.xml`), so they never touch the dev database.
- Frontend: Vite 8 + Tailwind 4. Filament ships its own compiled CSS/JS and does not go
  through the app's Vite build.
- **Two-factor is Filament's own**, not a package — `pragmarx/google2fa-qrcode` and
  `bacon/bacon-qr-code` arrive as Filament dependencies. Opt-in per user. See Access control.
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

- Activity log `event` keys (`role_granted`, `visit_deleted`, `records_pruned`,
  `two_factor_reset`), role names (`super_admin`) and permission names (`Delete:Activity`).
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
redirected to login.

**Log viewer** — the `viewLogViewer` gate in `AppServiceProvider` uses the same rule. Keep the
two in step: raw log files expose more than the panel does, so a weaker gate here would be a
way around the stronger one.
`LogViewerAccessTest::test_log_viewer_access_matches_panel_access` asserts they agree.

This gate is not optional: `opcodesio/log-viewer` only locks itself down when `APP_ENV` is
exactly `production` (`AuthorizeLogViewer` middleware checks `App::isProduction()`), so without
it staging and every other environment serve log contents to anonymous visitors.

**Managing users** — `/admin/users` (`app/Filament/Resources/Users/`) creates accounts, sets
passwords and assigns roles. Since a role is what grants access, this screen is how someone
gets into the panel at all. Note it does not restrict *which* role may be handed out: anyone
who can reach it can grant `super_admin`, including to themselves. That is fine while only
super admins hold `Create:User`, but a future staff role with user-management permissions
would be able to self-promote unless the role select is constrained.

**Permissions** — Shield generated 61 permissions named `Action:Subject` (`ViewAny:Activity`).
`super_admin` holds all of them and short-circuits every check through a `Gate::before` hook
(`filament-shield.super_admin.intercept_gate`). Regenerate after adding a resource or page:

```bash
php artisan shield:generate --all --panel=admin
php artisan shield:seeder --force     # refresh ShieldSeeder from the current database
```

`shield:setup` and `shield:super-admin` both throw `NonInteractiveValidationException` on a
chained prompt when run with `--force` in a non-TTY. Run `shield:install`, `shield:generate`
and `shield:seeder` individually instead.

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
`['name', 'email']`, and widening it to cover the secret column would write the secret into the
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
theirs at `/admin/profile` with a code, and an owner who has lost their device cannot reach
`/admin/users` in the first place.

It is gated on **holding the `super_admin` role by name**, and this is the one place in the
panel that checks a role rather than a permission. Two reasons, both deliberate:

- `/admin/users` already sets passwords. Clearing the second factor is what turns that into a
  complete account takeover, so it must not ride along with `Update:User` — a permission a
  future staff role would plausibly hold.
- A Shield permission could not express it anyway. `Gate::before` passes every check for super
  admins, so `can('ResetTwoFactor:User')` answers true for anyone who can reach the screen.

`TwoFactorAuthenticationTest::test_only_super_admins_may_clear_someone_elses_two_factor` builds
a role with `Update:User` and asserts it can edit the user but not clear their second factor.

`TwoFactorAuthenticationTest::test_a_correct_password_alone_does_not_sign_in_a_user_with_two_factor`
is the assertion that matters — without it every other test in that file still passes while
two-factor does nothing.

## Monitoring

Three tables, three screens, one retention page. All of it is reachable only with a role, like
the rest of `/admin`.

| Screen | Table | Written by |
|--------|-------|------------|
| `/admin/visits` | `visits_monitoring` | `App\Http\Middleware\RecordVisit` |
| `/admin/authentications` | `authentications_monitoring` | package listeners on `Login` / `Logout` |
| `/admin/activities` | `activity_log` | `LogsActivity`, `LogRoleChange`, `activity()` calls |
| `/admin/monitoring` | `monitoring_settings` | the retention form itself |

**The package's own routes are disabled, and must stay that way.**
`binafy/laravel-user-monitoring` registers six routes under `/user-monitoring` — three Blade
dashboards and three DELETE endpoints — through `LaravelUserMonitoringRouteServiceProvider`,
with the `web` middleware group and nothing else. No `auth`, no gate, and the controllers never
call `authorize()`: anonymous visitors could read every IP, page and login time, and delete the
rows. That provider loads `routes/user-monitoring.php` instead of its own file whenever the
former exists, so **that file is deliberately empty** and deleting it brings all six back.
`UserMonitoringTest::test_the_packages_own_routes_are_not_registered` asserts they stay gone.

**Config deviations** (`config/user-monitoring.php`, each commented in place):

- `action_monitoring` is off entirely. activitylog already records model changes with a
  per-column diff, a causer and a subject; this package's version stores only a table name.
  No model uses the `Actionable` trait. Never enable `on_read` — it hooks the `retrieved`
  event, so one `/admin/users` page writes a row per listed user.
- `authentication_monitoring.delete_user_record_when_user_delete` is `false`. The default
  `true` makes `user_id` cascade, so deleting an account at `/admin/users` erases its entire
  sign-in history — exactly what you would want to read after removing a suspicious account.
  `false` gives `nullOnDelete()`, matching the visits table. Only read at migration time.
- `delete_days` stays `0`. Retention is not configured here (see below), and `0` also keeps the
  package's own `laravel-user-monitoring:remove-visit-monitoring-records` inert — it refuses to
  run at `0`. Two commands pruning the same table from different cutoffs would be a mess.

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
only one place silently misses half the app.

### Retention

`delete_days` cannot live in config, because a screen cannot write to a config file:
`config:cache` compiles config into a single PHP file at deploy time, and generating PHP from
user input is how a settings page becomes remote code execution. So the cutoffs live in the
`monitoring_settings` table (one row, read via `MonitoringSetting::current()`), are edited at
`/admin/monitoring`, and are applied by `App\Console\Commands\PruneMonitoring`.

Null means keep forever. Activity log retention is blank by default on purpose — it holds the
record of deletions made on the other two screens.

**Nothing runs the schedule on its own.** `php artisan dev` starts `serve`, `queue:listen`,
`pail` and `vite` — no scheduler. Without `php artisan schedule:work` locally, or the usual
once-a-minute `schedule:run` cron on a server, retention is saved but never applied. The
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
| `/admin/visits` | `activity_log`, event `visit_deleted` | `VisitMonitoring::booted()` |
| `/admin/authentications` | `activity_log`, event `sign_in_deleted` | `AuthenticationMonitoring::booted()` |
| `/admin/activities` | the log file, at `/log-viewer` | `AppServiceProvider::registerActivityDeletionLogging()` |

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

## Gotchas

**Policies for vendor models are not auto-discovered.** Laravel maps `App\Models\X` to
`App\Policies\XPolicy`. `Activity` lives in a vendor namespace, so `ActivityPolicy` is
registered by hand in `AppServiceProvider::registerVendorModelPolicies()`. Without that line
the policy is silently ignored and every permission check on it passes. Shield prints a
"requires registration" note when generating such policies — do not skip it. Shield's own
`RolePolicy` is registered by its service provider and needs nothing.

`App\Models\VisitMonitoring` and `App\Models\AuthenticationMonitoring` exist only to dodge this
trap: they subclass the package models so discovery reaches them, and Shield generated their
policies without a "requires registration" note. They also pin `getTable()` to the config key,
because the package models hardcode `$table` while its middleware inserts into
`config('user-monitoring.*.table')`.

**Model config uses PHP attributes, not properties.** `app/Models/User.php` declares
`#[Fillable([...])]` and `#[Hidden([...])]` above the class. Do not add `protected $fillable`
alongside them — pick the attribute style the file already uses. Other models use plain
properties; match the file you are editing.

`#[Fillable]` on `User` lists three columns and must keep listing exactly those. The two-factor
columns are absent on purpose: they are written by direct assignment, and a fillable secret is
settable from any request that reaches a user form.

**`User::booted()` is already taken**, by the two-factor audit hook. Eloquent allows one
`booted()` per class, so a second definition silently replaces the first rather than erroring —
add listeners inside the existing method.

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
`bootstrap/app.php`. Middleware added to the app's `web` group does not apply to `/admin`.
`RecordVisit` is the worked example — it is listed in both places.

**Any test that hits a route needs `RefreshDatabase`.** Every `web` request now writes to
`visits_monitoring`. `RecordVisit` survives a missing table, but a test without migrations only
proves the fallback works. `ExampleTest` was changed for this.

**Indonesian has no plural inflection.** Filament pluralises a resource's `$modelLabel` unless
`$pluralModelLabel` is set too, which produces "Penggunas". Every resource sets both to the
same word.

## Seeded credentials

`DatabaseSeeder` calls `ShieldSeeder` then `AdminUserSeeder` — that order matters, because the
admin account is made usable by `syncRoles([super_admin])` and the role has to exist first.
The account is `admin@admin.com` / `admin`. Deliberately weak and local-only — there is no
environment guard on the seeder, so do not run `--seed` against a production database.

The seeded account has no second factor, and cannot be given one from a seeder — the secret has
to be paired with a phone at `/admin/profile`. Anywhere this account exists, two-factor is
protecting nothing.

`ShieldSeeder` is generated, not hand-written: `php artisan shield:seeder --force` snapshots
the current roles and permissions into it. Regenerate it whenever permissions change, or a
fresh database will come up missing them.

## Audit log

`User` uses `LogsActivity` with an explicit allowlist: `logOnly(['name', 'email'])`,
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

When adding the trait to another model, keep the same shape: name the log, list attributes
explicitly, and add a test asserting secrets never appear in `attribute_changes`
(`UserActivityLoggingTest` has one to copy).

The UI is `app/Filament/Resources/Activities/`. `canCreate()` and `canEdit()` return `false`,
so Filament never registers create or edit routes — an editable audit entry is worse than a
deleted one, because it still reads as true. Keep it that way. Deletion **is** allowed, gated
by `Delete:Activity` / `DeleteAny:Activity` and logged to the file log; see Monitoring for the
full chain. The query eager-loads `causer` and `subject` because both are morphs and cannot be
joined.

Log names in use: `user` (model changes, role grants, two-factor changes) and `monitoring`
(deletions, prunes).
Descriptions are Indonesian; `event` keys are not — see Locale and timezone.

## Filament conventions

- Resources, Pages and Widgets are auto-discovered from `app/Filament/{Resources,Pages,Widgets}`.
  Creating a class there is enough; no manual registration.
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
- An action that weakens someone else's security should ask for the actor's **own** password:
  `TextInput::make('password')->password()->required()->currentPassword()`.
  `->requiresConfirmation()` stops a misclick; it does not stop a passer-by at an unlocked
  screen. `ResetTwoFactorAction` is the worked example.
- Actions mounted in more than one place (a table row *and* a page header) belong in their own
  class under `Resources/<Name>/Actions/`, returning a configured `Action` from a static
  `make()`. Filament's own MFA actions have that shape. Two copies of an authorization rule is
  one copy too many.
- Before deploying run `php artisan filament:optimize` — caches component discovery and Blade
  icons. Without it every request pays a directory scan. Re-run `filament:optimize-clear` after
  editing the panel provider, or the cached component list masks your change.

## Tests

`tests/Feature` covers the security-relevant behaviour; run the suite before changing any of it.
90 tests at the last count.

| File | Locks in |
|------|----------|
| `PanelAccessTest` | roleless/super-admin/guest access, removing the last role locks out |
| `UserActivityLoggingTest` | what is logged, what is never logged, causer, role grant/revoke |
| `ActivityLogPanelTest` | list and view render, no create/edit, deletes go to the file log |
| `LogViewerAccessTest` | guests and roleless users blocked from the page *and* the API |
| `UserResourceTest` | password hashing, blank-password edits, confirmation, self-delete refusal |
| `UserMonitoringTest` | package routes stay gone, panel middleware coverage, delete auditing |
| `MonitoringRetentionTest` | retention saves, blank means forever, prune scope and summary |
| `TwoFactorAuthenticationTest` | password alone is refused, valid code passes, secret never leaks, three audit events, admin reset |
| `PageViewsOnlyTest` (Unit) | which requests count as a visit |

`Tests\TestCase` provides `userWithRole()`, `superAdmin()` and `seedRoles()`. Roles come from
`ShieldSeeder` so tests exercise the same data a deploy produces, and the permission cache is
cleared afterwards — without that, a role created mid-test stays invisible to `Gate` checks.

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
