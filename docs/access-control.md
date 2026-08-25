# Access control

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
by a role. It carries receipt photographs, meter photographs and a sale's transfer receipts and
courier resi alike, serving the private disk on a signed, expiring URL, so within that window
the link works for whoever holds it, signed in or not. The last of those is worth naming
separately: a resi carries the customer's home address in a form nothing can redact — it is a
photograph. That is the weakest of the three gates by design; what it protects and what it does not
are set out under Media.

**Home addresses are now held in three places, not one.** `customers.address` is the readable
copy, gated by the customer policy like any other column; a resi is the photographed copy behind
the signed link above; and `activity_log` holds every previous value, because `address` is on the
Customer allowlist (see Oriflame for why). The third is the loosest: activity-log retention is
blank by default (see Monitoring), and `ViewAny:Activity` is a different permission from
`ViewAny:Customer` — so a role given the log but not the customer list can still read where
people live. Nothing today grants that combination; it is a shape to check before a staff role
is added.

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

**Permissions** — Shield generated 133 permissions named `Action:Subject` (`ViewAny:Activity`).
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

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
