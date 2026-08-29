<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What makes the panel installable to a phone's home screen.
 *
 * All of it is static files in public/ plus one render hook, so none of it is
 * exercised by any other test and every part of it fails silently. A manifest
 * naming an icon that is not there installs an app with a blank square; a
 * service worker caching a URL that has been renamed installs a worker whose
 * offline page is a 404; a head hook that stops being registered leaves a site
 * that simply never offers to install, with nothing in the log either way.
 *
 * The first test is the one worth keeping past a refactor. The manifest's
 * `name` is a literal, because the manifest is a static file rather than a
 * route (see resources/views/filament/pwa.blade.php for why), and APP_NAME is
 * the single variable the topbar, the login card and the <title> all follow.
 * Without an assertion tying the two together, renaming the app renames
 * everything except the icon on the home screen.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true);

        $this->assertIsArray($manifest, 'manifest.webmanifest is not valid JSON');

        return $manifest;
    }

    public function test_the_manifest_name_matches_the_configured_app_name(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(config('app.name'), $manifest['name']);
        $this->assertSame(config('app.name'), $manifest['short_name']);
    }

    public function test_the_manifest_scope_covers_the_whole_panel(): void
    {
        $manifest = $this->manifest();

        // The panel owns the root path, so anything narrower would drop the
        // user back into a browser tab the moment they left the start page.
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_every_icon_the_manifest_names_exists(): void
    {
        $icons = $this->manifest()['icons'];

        $this->assertNotEmpty($icons);

        foreach ($icons as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "manifest names a missing icon: {$icon['src']}");
        }

        // Android masks an icon to whatever shape the launcher uses, and falls
        // back to shrinking an `any` icon inside a white circle when no
        // maskable one is offered.
        $this->assertContains('maskable', array_column($icons, 'purpose'));
    }

    public function test_ios_gets_its_own_icon_because_it_reads_none_of_the_manifest(): void
    {
        $this->assertFileExists(public_path('icons/apple-touch-icon-180.png'));
    }

    public function test_the_service_worker_caches_the_offline_page_that_exists(): void
    {
        $worker = public_path('sw.js');

        $this->assertFileExists($worker);
        $this->assertFileExists(public_path('offline.html'));

        // The worker precaches this one URL on install. Renaming the file
        // without renaming it here installs a worker whose only cached
        // response is a 404, and nothing reports it until the network drops.
        $this->assertStringContainsString("'/offline.html'", file_get_contents($worker));
    }

    public function test_the_login_page_carries_the_install_metadata(): void
    {
        // /login rather than a page behind auth: it is the only page a
        // signed-out user can reach, so it is where an install actually
        // starts. It is a panel page, so it renders the same head hook.
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('rel="manifest"', false);
        $response->assertSee(asset('manifest.webmanifest'), false);
        $response->assertSee('rel="apple-touch-icon"', false);
        $response->assertSee('apple-mobile-web-app-capable', false);
        $response->assertSee('serviceWorker', false);
        $response->assertSee(asset('sw.js'), false);
    }

    public function test_the_status_bar_colour_is_declared_for_both_schemes(): void
    {
        // A single theme-color is the trap here: it is applied in both
        // schemes, so whichever one it was picked for, the other gets a bar
        // that does not match the page under it.
        $response = $this->get('/login');

        $response->assertSee('media="(prefers-color-scheme: light)"', false);
        $response->assertSee('media="(prefers-color-scheme: dark)"', false);
    }
}
