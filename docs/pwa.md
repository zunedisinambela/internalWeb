# PWA — memasang panel ke home screen

The panel installs to an Android or iOS home screen from the browser, with no
store listing and no second codebase. What that costs is four static files and
one render hook; what it buys is a launcher icon, a window with no browser
chrome, and its own entry in the task switcher.

## Berkas

| File | What |
|------|------|
| `public/manifest.webmanifest` | name, icons, scope, colours |
| `public/sw.js` | service worker — offline page only, no data cached |
| `public/offline.html` | shown when a navigation fails, fully self-contained |
| `public/icons/*.png` | 192, 512, maskable 512, apple-touch 180, favicon 16/32 |
| `resources/views/filament/pwa.blade.php` | the head tags, on `PanelsRenderHook::HEAD_END` |

`tests/Feature/PwaTest.php` covers all of it. Everything here fails silently —
read the docblock there before deleting an assertion that looks redundant.

## Nothing is cached but the offline page

This is the decision the rest follows from. Every screen in this panel is a
figure somebody acts on: a saldo, a bill, an order total. A cached page carries
no indication that its numbers are an hour old, and the app has no way to mark
one as stale after the fact. Livewire settles it independently — the CSRF token
is baked into the markup, so a page replayed from cache is a page whose next
click fails validation, and `databaseNotificationsPolling('30s')` would be
polling a frozen document.

So the worker exists for two narrower reasons. Chrome will not offer to install
a site without a service worker that has a fetch handler; and a network failure
inside a standalone window has no address bar to explain itself, so the
browser's own error page is chrome the installed app does not have.

Two paths are deliberately left untouched by the worker: anything that is not a
navigation — Livewire's POSTs, the notification polling, images, fonts — and
anything under `/storage/`, which is where signed report downloads open in a
new tab. See the comments in `sw.js`.

Bump `CACHE` in `sw.js` whenever `offline.html` changes. `activate` deletes
every cache but the current one, so the old copy goes with it.

## The manifest is a static file, so its name is a literal

The panel owns the root path, so every route added here shares one namespace
with the resource slugs (see the empty-path note in `CLAUDE.md`), and a
manifest is a constant that gains nothing from being rendered through Blade.

The cost is that `name` cannot read `config('app.name')`, which is the single
variable the topbar, the login card and the `<title>` all follow. `PwaTest::
test_the_manifest_name_matches_the_configured_app_name` ties the two back
together: rename the app and that test fails, rather than the home screen icon
quietly keeping the old label.

## iOS reads almost none of it

- **No install prompt.** Android Chrome offers one; iOS never does. The user
  taps Share → *Tambahkan ke Layar Utama*. Anything in the UI that points at
  installing has to say so per platform.
- **No manifest icons.** Safari wants `<link rel="apple-touch-icon">`, wants a
  PNG, and composites it onto black wherever the image is transparent — which
  is why `apple-touch-icon-180.png` is a flat opaque square while the manifest
  icons carry their own rounded corners.
- **`display: standalone` is not enough.** `apple-mobile-web-app-capable` is
  still what opens the app without browser chrome.
- **Its own cookie jar.** An installed iOS app does not share a session with
  Safari, so the first launch is a fresh sign-in even for a user already signed
  in in the browser.
- **`black`, not `black-translucent`,** for the status bar: translucent draws
  the page underneath the clock, which puts Filament's topbar behind it.

## Colours

`theme_color` in the manifest is the brand amber — it is used for the Android
splash screen and the task switcher, where the point is recognising the app.

The two `<meta name="theme-color">` tags in the head are a different job: they
colour the status bar *around the running app*, so they follow the panel's
actual background instead. Filament's gray is Zinc by default, so that is
zinc-50 (`#fafafa`) light and zinc-950 (`#09090b`) dark. One value for both
schemes would be a visible seam above the page in whichever scheme it was not
picked for.

## Requires HTTPS

Service workers need a secure context. Production is behind Caddy with a Let's
Encrypt certificate, so that holds; `composer dev` serves `localhost`, which
counts as secure by exemption. Any other unencrypted origin — an IP on the LAN,
a staging box on plain HTTP — registers nothing, and the app is still usable in
the browser but will not install. The registration is guarded on
`window.isSecureContext` so that is a no-op rather than a console error.

## Mengganti ikon

The icons are `IW` on amber, generated rather than drawn. To replace them with
a real logo, overwrite the six PNGs in `public/icons/` at the sizes their names
give, keeping two rules: the maskable one must hold its content inside the
central 80% (every launcher shape crops to at least that), and the
apple-touch-icon must be opaque.
