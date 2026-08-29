# Monitoring

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

### Who a visit is attributed to

`user_id` is `UserUtils::getUserId()` read **before** the request is handled — `RecordVisit`
inserts its row, then runs the pipeline. The row is an insert and is never returned to, so the
attribution means "who was signed in when this arrived", not "who this turned out to be".

That makes `/login` a guest row permanently. It is the one page a signed-out user can reach, so
`user_id` is null there by definition, and the Kunjungan screen renders null as *Tamu*
(`VisitsTable`, `->placeholder('Tamu')` — the placeholder is deliberate, since a null here is
expected data rather than a missing join). A successful sign-in five seconds later does not
backfill it.

Nor does the credential submission show up anywhere: Filament's login is a Livewire component,
and `PageViewsOnly` rejects Livewire by header. A whole sign-in therefore leaves exactly two
traces in this table — one *Tamu* hit on `/login`, and then the first authenticated row after
the redirect. A run of *Tamu* hits with no authenticated row following is a failed attempt, and
that shape is the reason `guest_mode` is `true`: dropping null rows would erase the record of
both a person who forgot their password and a probe.

**"Who signed in, and when" is the other table.** `authentications_monitoring` is written by
`LaravelUserMonitoringEventServiceProvider`, which listens for `Login` and `Logout` — events
that fire *after* authentication, so the user is known. Each row carries an `action_type`, so a
sign-out is not misread as a sign-in; a logout is what puts the user back on `/login`, and
without that column a burst of guest `/login` rows following a logout looks identical to a
burst preceding a failed login.

Kunjungan answers *what was requested*. Riwayat Masuk answers *who it was*. Asking the first
screen the second question returns *Tamu* every time, and it is not wrong when it does.

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

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
