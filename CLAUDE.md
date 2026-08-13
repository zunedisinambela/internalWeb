# internalWeb

Internal admin web app. Laravel 13 + Filament v5 panel. PHP 8.3+ (dev runs 8.4).

## Commands

```bash
composer setup       # install, .env, key:generate, migrate, npm install + build
composer dev         # -> php artisan dev (server, queue, logs, vite in one process)
composer test        # config:clear then php artisan test (PHPUnit 12, not Pest)
vendor/bin/pint      # format; no pint.json, so Laravel preset defaults apply
php artisan migrate:fresh --seed   # rebuild sqlite + seed admin user
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
- **Database is SQLite** (`database/database.sqlite`), gitignored via `database/.gitignore`.
  Tests run against `:memory:` (see `phpunit.xml`), so they never touch the dev database.
- Frontend: Vite 8 + Tailwind 4. Filament ships its own compiled CSS/JS and does not go
  through the app's Vite build.
- `laravel/pao` is installed — agent-optimized output for PHP testing tools.

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

**Permissions** — Shield generated 24 permissions named `Action:Subject` (`ViewAny:Activity`).
`super_admin` holds all of them and short-circuits every check through a `Gate::before` hook
(`filament-shield.super_admin.intercept_gate`). Regenerate after adding a resource:

```bash
php artisan shield:generate --all --panel=admin
php artisan shield:seeder --force     # refresh ShieldSeeder from the current database
```

## Gotchas

**Policies for vendor models are not auto-discovered.** Laravel maps `App\Models\X` to
`App\Policies\XPolicy`. `Activity` lives in a vendor namespace, so `ActivityPolicy` is
registered by hand in `AppServiceProvider::registerVendorModelPolicies()`. Without that line
the policy is silently ignored and every permission check on it passes. Shield prints a
"requires registration" note when generating such policies — do not skip it. Shield's own
`RolePolicy` is registered by its service provider and needs nothing.

**Model config uses PHP attributes, not properties.** `app/Models/User.php` declares
`#[Fillable([...])]` and `#[Hidden([...])]` above the class. Do not add `protected $fillable`
alongside them — pick the attribute style the file already uses.

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

## Seeded credentials

`DatabaseSeeder` calls `ShieldSeeder` then `AdminUserSeeder` — that order matters, because the
admin account is made usable by `syncRoles([super_admin])` and the role has to exist first.
The account is `admin@admin.com` / `admin`. Deliberately weak and local-only — there is no
environment guard on the seeder, so do not run `--seed` against a production database.

`ShieldSeeder` is generated, not hand-written: `php artisan shield:seeder --force` snapshots
the current roles and permissions into it. Regenerate it whenever permissions change, or a
fresh database will come up missing them.

## Audit log

`User` uses `LogsActivity` with an explicit allowlist: `logOnly(['name', 'email'])`,
`logOnlyDirty()`, `dontLogEmptyChanges()`, log name `user`. The allowlist is deliberate — the
table holds password hashes and remember tokens, and `logAll()` cannot stay safe as columns are
added.

**Role changes are audited separately.** `LogsActivity` watches columns, and roles live in a
pivot table, so it cannot see them at all. `App\Listeners\LogRoleChange` listens for
`RoleAttachedEvent` / `RoleDetachedEvent` and writes `role_granted` / `role_revoked` entries
with the role names in `properties`. Since a role is what grants panel access, this *is* the
privilege-escalation trail — if it stops working the log looks healthy while missing the most
important events.

When adding the trait to another model, keep the same shape: name the log, list attributes
explicitly, and add a test asserting secrets never appear in `attribute_changes`
(`UserActivityLoggingTest` has one to copy).

The UI is `app/Filament/Resources/Activities/`, a read-only resource: `canCreate()`,
`canEdit()`, `canDelete()` and `canDeleteAny()` all return `false`, so Filament never registers
create or edit routes. Keep it that way — an editable audit trail is worthless. Its query
eager-loads `causer` and `subject` because both are morphs and cannot be joined.

## Filament conventions

- Resources, Pages and Widgets are auto-discovered from `app/Filament/{Resources,Pages,Widgets}`.
  Creating a class there is enough; no manual registration.
- Generate with `php artisan make:filament-resource`, `make:filament-page`, `make:filament-widget`.
- v5 renamed the filter builder: use `->schema([...])`, not the deprecated `->form([...])`.
- Infolist `KeyValueEntry` renders scalars only. Non-scalar values must be stringified first —
  see `ActivityInfolist::stringifyValues()`.
- `CodeEntry` requires the `phiki` package, which is not installed. Use a `TextEntry` with
  `FontFamily::Mono` for JSON blobs instead.
- Dashboard widgets: `AccountWidget` only. Filament's default `FilamentInfoWidget` (version /
  docs / GitHub branding card) was deliberately removed from `->widgets([...])` — do not add it
  back when regenerating or upgrading the panel provider.
- Before deploying run `php artisan filament:optimize` — caches component discovery and Blade
  icons. Without it every request pays a directory scan. Re-run `filament:optimize-clear` after
  editing the panel provider, or the cached component list masks your change.

## Tests

`tests/Feature` covers the security-relevant behaviour; run the suite before changing any of it.

| File | Locks in |
|------|----------|
| `PanelAccessTest` | roleless/super-admin/guest access, removing the last role locks out |
| `UserActivityLoggingTest` | what is logged, what is never logged, causer, role grant/revoke |
| `ActivityLogPanelTest` | list and view pages render, resource stays read-only |
| `LogViewerAccessTest` | guests and roleless users blocked from the page *and* the API |

`Tests\TestCase` provides `userWithRole()`, `superAdmin()` and `seedRoles()`. Roles come from
`ShieldSeeder` so tests exercise the same data a deploy produces, and the permission cache is
cleared afterwards — without that, a role created mid-test stays invisible to `Gate` checks.

The log viewer's API is tested separately from its UI because it has its own middleware stack
(`api_middleware` in `config/log-viewer.php`) and returns raw log contents.
