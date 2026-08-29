{{--
    Everything needed to install the panel to a phone's home screen.

    Registered on PanelsRenderHook::HEAD_END, which puts it on every panel page
    including /login — the only page a signed-out user can reach, and therefore
    the only page an install can start from. HEAD_END renders before Filament's
    own stylesheet, which matters for CSS and not at all for link and meta tags.

    The manifest itself is a static file in public/ rather than a route. The
    panel owns the root path, so every route added here shares one namespace
    with the resource slugs (see the empty-path note in CLAUDE.md), and a
    manifest is a constant that gains nothing from being rendered. The cost is
    that its `name` cannot read config('app.name') — PwaTest asserts the two
    match so that renaming the app fails a test rather than leaving the home
    screen icon labelled with the old one.
--}}

<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

{{--
    The manifest's theme_color is one value and is used for the Android splash
    and task switcher, where the brand colour is what identifies the app. These
    two are what colour the status bar around the running app, so they follow
    the panel's actual background instead: Filament's gray is Zinc by default,
    and its body is zinc-50 light, zinc-950 dark. A brand-coloured bar here
    would read as a seam above a page that is not that colour.
--}}
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#fafafa">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#09090b">

{{-- favicon.ico in this project is a zero-byte placeholder; these are real files. --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('icons/favicon-16.png') }}">

{{--
    iOS reads none of the manifest's icons. It wants this link, it wants a PNG,
    and it composites whatever it gets onto black wherever the image is
    transparent — so apple-touch-icon-180.png is a flat opaque square while the
    manifest icons carry their own rounded corners.
--}}
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon-180.png') }}">

{{--
    Safari still needs its own capability flag to open the app without browser
    chrome; the manifest's `display` alone does not do it on iOS. `black` keeps
    the status bar opaque — `black-translucent` draws the page underneath it,
    which puts Filament's topbar behind the clock.
--}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

<script>
    // Registered on load rather than immediately: the worker's install fetches
    // the offline page, and doing that while the page it is on is still
    // fetching its own assets is bandwidth taken from the thing the user is
    // waiting for.
    //
    // isSecureContext rather than a protocol check — it is true on localhost
    // over plain HTTP, which is what `composer dev` serves, and false on any
    // other unencrypted origin, where registration would throw.
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' });
        });
    }
</script>
