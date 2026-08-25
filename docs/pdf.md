# PDF

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

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
