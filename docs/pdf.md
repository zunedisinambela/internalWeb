# PDF

`barryvdh/laravel-dompdf`, rendering through `dompdf/dompdf` v3 in pure PHP — no headless
Chrome, no `wkhtmltopdf`, nothing to install on the host. The cost is a renderer with roughly
CSS 2.1 support: no flexbox, no grid, no modern layout. Build PDF Blade views with tables and
floats, not with anything borrowed from the panel's Tailwind.

Four reports exist, one per feature list screen:

| View | Screen | Report class | Job |
|------|--------|--------------|-----|
| `pdf/buku-kas.blade.php` | `/transactions` | `App\Reports\CashBook` | `ExportCashBook` |
| `pdf/penjualan.blade.php` | `/sales` | `App\Reports\SalesReport` | `ExportSales` |
| `pdf/pelanggan.blade.php` | `/customers` | `App\Reports\CustomerReport` | `ExportCustomers` |
| `pdf/meteran-listrik.blade.php` | `/meter-readings` | `App\Reports\MeterReadingReport` | `ExportMeterReadings` |

No route serves a PDF, and since the render moved onto the queue no *response* carries one
either: an `App\Jobs\ExportReport` subclass writes the bytes to the private disk and the user is
handed a signed link. See Keuangan.

**The four views share everything but their columns.** `resources/views/pdf/partials/` holds the
stylesheet (`gaya`), the heading block (`kop`), the summary cards (`ringkasan`) and the evidence
cell (`bukti`); a report view is its `<table>` and nothing else. That is not tidiness — it is the
only way the four documents stay recognisably the same document. dompdf gives no error for a rule
it cannot parse, so a stylesheet copied into a fifth view and edited there diverges in silence.

Two things about the partials worth knowing before writing that fifth view:

- **`kop` takes an array of phrases, not a sentence.** The separator between them is `&middot;`,
  which is *markup*, and every phrase goes through `{{ }}` — so a report that assembles
  `"2 penjualan &middot; 30 barang"` in PHP prints the entity verbatim on the page. It is a bug
  no HTML assertion catches, because the escaped form is exactly what a correctly-escaped user
  string looks like. `ReportExportTest::test_the_header_separator_is_rendered_and_not_printed_as_an_entity`
  is what tells the two cases apart.
- **`ringkasan` divides the row evenly** from the number of cards it is handed, so three cards and
  four cards both fill the width with no per-report CSS.

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
page — `App\Jobs\ExportReport::stampFooter()` does it once for all four reports. `$font` is a font *file*, not a family — resolve it with
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
range.

**It does not cover U+2212, the true minus sign**, and that is the one gap with a consequence
rather than a cosmetic tell. The panel renders a negative amount with U+2212 on screen; a report
that copied it would print the digits with no sign at all, because dompdf drops a missing glyph
in silence. `App\Support\Rupiah::format()` uses an ASCII hyphen for that reason, and puts it
*before* the "Rp" — `number_format()` alone yields "Rp -1.830.000", which reads like a currency
named "Rp -". All four reports format money through it, so the two decisions are made once. `config/dompdf.php` sets `default_font` to `serif`, so an unstyled view gets Times.

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

### Printing an attachment

Three of the four reports print the photographs themselves rather than a count of them — the cash
book's receipts, a sale's transfer receipt and resi, a reading's two dial photographs. That is
what the Bukti column is for: a disputed figure is settled by looking at the evidence beside it,
and a column reading "2" settles nothing.

`App\Support\PdfImage` is the only thing that decides what may be printed, and it answers with a
path or with null:

```php
PdfImage::paths($record->media, Sale::SHIPPING_PROOFS, Sale::THUMBNAIL)   // array<int, string>
```

Four decisions are baked into it, each of which fails silently if reversed:

- **A filesystem path, not a URL.** `enable_remote` is false, so dompdf will not fetch `http://`
  at all — and it must not: a signed link to the private disk goes back through the app to fetch
  a file the renderer is already standing next to. Attachments live under `storage/app/private`,
  which is inside `base_path()`, which is dompdf's `chroot`.
- **Not a `data:` URI either.** It works, and it was rejected on memory: dompdf reads a path
  lazily, one image at a time, where base64 would put every photograph in the report into one HTML
  string at four-thirds of its size. A year of meter readings is a few hundred photographs.
- **Always the `thumb` conversion, never the original.** The re-encode is what drops the EXIF the
  phone wrote, GPS included, and an export is a file that leaves the building. So a *missing*
  conversion yields null rather than falling back — a visible gap in one cell beats a silent leak
  in every one. See the EXIF note in CLAUDE.md's Gotchas.
- **Null for a file that is gone, or a disk moved outside the project.** dompdf answers all three
  cases by drawing nothing and logging nothing, `show_warnings` being false, so the check has to be
  ours; the `bukti` partial prints a dash where the photograph would be.

**A cell fits two thumbnails, and the rest are counted rather than dropped.** The partial prints
`+n` for the overflow. A report that truncates its own contents without saying so reads as though
that was all there was.

**The `src` attribute is user-controlled text.** It is assembled from the file name somebody
uploaded, so it goes through `{{ }}` like any other value — a file name containing a quote would
otherwise break out of the attribute, into a parser whose chroot is the project root. This is the
one place where the escaping rule below applies to something that does not look like prose.

**The eager load is the report's job, not the view's.** Each report constrains `media` to the
collections it prints, so the view reads an already-loaded relation. Drop it and every report
becomes a query per row, which is invisible on the four-row screen it was tested against.

**A PDF is a read surface, so it is gated and audited like any other.** Generating one fires no
model event, so nothing records it unless the caller does. Every report here does the same two
things: authorization on the resource (`TransactionResource::canExport()` and its three siblings,
each returning `canViewAny()`) and an `activity()` entry under the `monitoring` log name. The
format is a *property* of that entry rather than a second event key — downloading a report is one
act and the extension is a detail of it — while each screen has its own event key, so "who took a
copy of the customer list" is one filter rather than a scan of every properties blob. A PDF of
records the caller cannot open in the panel would be a way around the policy that guards the
screen.

**Interpolating user text is where `chroot` stops being theoretical.** All four views render text
someone typed into a form — a description, a note, a customer's name and address — so every value
in them goes through `{{ }}`.
`TransactionExportTest::test_a_description_is_escaped_rather_than_parsed_as_markup` and
`ReportExportTest::test_user_text_is_escaped_rather_than_parsed_as_markup` cover the four between
them. The second is a data provider with one case per view rather than one assertion over the
shared partials, and deliberately so: the escape is per interpolation, so a single `{!! !!}` added
to any one view is the whole exposure.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.
