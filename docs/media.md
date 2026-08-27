# Media

`spatie/laravel-medialibrary` attaches files to Eloquent models via the `media` table, and
resizes them through `spatie/image` v3, which arrives as its own dependency. That is the only
image stack here — `intervention/image` was installed alongside it briefly and removed again,
since medialibrary already carries everything needed to manipulate an image.

`env('IMAGE_DRIVER')` belongs to `config/media-library.php` and takes the short string `gd` or
`imagick`. Should a second image package ever be added, give it its own env key: Intervention's
published config claims this exact one but expects a fully-qualified driver class instead, so
sharing it means setting the variable breaks whichever package did not get the format it
wanted — while the package that *appears* broken is not the one whose setting changed.

**Four models use it, across six collections**: `App\Models\Transaction` through `receipts`
(see Keuangan), `App\Models\MeterReading` through `meter-photos-start` and `meter-photos-end`
(see Listrik kost) — a collection per meter figure, so a photograph says for itself which number
it is evidence for — `App\Models\Sale` through `payment-proofs` and `shipping-proofs` (see
Oriflame), on the same reasoning: a transfer receipt and a courier resi answer different
questions, and a single collection could only tell them apart by upload order — and
`App\Models\FreeItemRedemption` through `shipping-proofs`, the resi of a free item handed over.

That last one reuses a collection *name* rather than inventing one, and it is not a collision:
media rows are keyed by morph, so `shipping-proofs` on a redemption and `shipping-proofs` on a
sale are separate sets that never meet. Naming it to match says the two hold the same kind of
evidence; the model it hangs off says which event it is evidence of.

**A collection per kind of evidence is the pattern here, not a quirk of two features.** Both
splits exist because `collection_name` is a column nothing in the UI can scramble, while upload
order is destroyed by reordering or by deleting one file — silently, with the pairing shifted
and nothing saying so. When a third model attaches files that mean more than one thing, split
them the same way rather than reaching for `custom_properties`, which is a free-form JSON bag
with no validation and no constraint.

Transaction settled the
disk question this section used to leave open, and both later models followed it without
reopening it — the answer is binding on whatever attaches files next: **the private `local`
disk, not `public`**.
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
(log `transaction`, event `receipt_deleted`), `MeterReading` (log `meter_reading`, event
`meter_photo_deleted`) and `Sale` (log `sale`, event `sale_attachment_deleted`). Attaching and replacing a file are **not** recorded, and a model absent
from the map is not recorded at all. Media is a relation, so `LogsActivity` cannot see it — this
is the same split `LogRoleChange` makes for roles.

**Add to the map, never a second listener.** `Media::deleted` fires for every model in the app,
so a listener per owner means a full check per deletion for each one, and as many places for
the shape of the entry to drift. The log name and event key differ per owner deliberately:
filtering the log for "a receipt was removed from the cash book" must not also return meter
photographs.

**The map is keyed by owner, not by collection**, and that is the right granularity.
`MeterReading` has two collections and both write `meter_photo_deleted`; `Sale` has two and both
write `sale_attachment_deleted`. Which collection lost the file is in the entry's `collection`
property, which the listener writes for every owner already. Splitting the key per collection
would mean two event keys to remember for one question.

Adding `LogsActivity` to the media model itself would need the usual explicit allowlist —
`file_name` and `collection_name`, never `custom_properties`, which is a free-form JSON bag
whose contents nobody controls centrally.

**Clicking an image opens it full size, and that is a render hook rather than a
component** — see Panel CSS and JS under Filament conventions for why the panel's own CSS and
JS arrive that way. `resources/views/filament/lightbox.blade.php` is injected at
`PanelsRenderHook::BODY_END` and attaches to any element carrying `data-lightbox`,
treating every `<a>` inside it as one slide — pan, wheel and pinch zoom, arrow-key
paging. Opting a screen in is two calls on the entry: `->extraAttributes(['data-lightbox' => …])`
on the wrapper and a **state-based** `->url()`. `TransactionInfolist` is the worked example.

Three things about it fail silently:

- `ImageEntry` only wraps each image in an `<a>` when `->url()` is given a closure
  **declaring a parameter named `state`** — `CanOpenUrl::hasStateBasedUrls()` looks it up
  by name. Rename it to `$uuid` and every thumbnail links to the same file while still
  rendering perfectly. `test_each_receipt_is_its_own_link_on_the_view_screen` uses two
  receipts for exactly that reason; one would pass either way.
- The `href` is a signed link to the **original**, not to the conversion the thumbnail
  shows. That is the intended path — the original is meant to take a deliberate signed
  request — but it is also the EXIF-bearing copy, so weigh it per collection rather than
  copying the call blindly onto meter photographs or a sale's resi.
- The expiry mirrors `SpatieMediaLibraryImageEntry::getImageUrl()`
  (`filament.temporary_file_url_expiry_minutes`, rounded with `endOfHour()`). Diverge from
  it and the thumbnail outlives the file behind it, or the other way round.

The `href` stays a real link and the script only intercepts an unmodified click, so a JS
failure degrades to opening the file rather than to a dead thumbnail, and a ctrl- or
cmd-click still opens a tab.

**Two view screens use it: the cash book's and the Oriflame sale's.** The meter readings did
not opt in, and that is left rather than decided — the two calls are cheap, but the `href`
points at the EXIF-bearing original, which is a different trade-off for a meter bolted to a
building than for a receipt or a transfer slip.

**A screen with more than one collection needs a key per collection**, not one for the screen.
The script treats every `<a>` inside one `data-lightbox` element as a slide of the same set, so
sharing a key silently merges two kinds of evidence into one strip. `SaleInfolist` passes the
collection name; see Oriflame.

The `Bukti` column on the cash book *list* (`TransactionsTable`) is not wired up either, for an
unrelated reason: the cell sits inside a row that has its own click behaviour, and which of the
two wins was not established. Two calls would add it — the same two — but check that first.

**Filament integration is `filament/spatie-laravel-media-library-plugin` v5.7.6**, a separate
package from Filament itself, and the source of `SpatieMediaLibraryFileUpload`,
`SpatieMediaLibraryImageColumn` and `SpatieMediaLibraryImageEntry`. Note it constrains
`spatie/laravel-medialibrary` to `^11.0` — a second reason the v11 line was the right choice
over the unreleased v12.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
