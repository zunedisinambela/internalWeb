<?php

namespace Tests\Feature;

use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Transactions\Widgets\TransactionOverview;
use App\Models\Transaction;
use App\Support\PanelCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the panel's cross-request cache is allowed to do, and where it stops.
 *
 * The badges are cheap to get wrong quietly: a stale figure renders exactly
 * like a fresh one, so nothing here asserts "it is fast" — every test asserts
 * either that a query did not run, or that a changed row is visible anyway.
 */
class PanelCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The resource holds its per-request figure in a static property, and a
     * static outlives a test — RefreshDatabase rebuilds the schema and the
     * array cache store is rebuilt with the container, but the class is not
     * reloaded. Without this, a test reads the figure the previous test left
     * behind and the cache layer is never exercised at all.
     *
     * The same shape would bite in production under a persistent worker
     * (Octane, Swoole), where the static would outlive the request it was
     * scoped to. Under PHP-FPM the process ends with the response, which is the
     * assumption these statics were written against.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetRequestMemo();
    }

    /**
     * Counts the aggregate queries a callback issues.
     *
     * @param  \Closure(): mixed  $callback
     */
    protected function countQueries(\Closure $callback): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }

    /**
     * Reaches the resource's protected badge helper. Filament calls it through
     * getNavigationBadge(), but that formats the figure into a string, and a
     * test that asserts on "Rp 1.500.000" would fail for a formatting change.
     */
    protected function balance(): int
    {
        $method = new \ReflectionMethod(TransactionResource::class, 'balance');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    /**
     * Clears the per-request static the resource holds alongside the cache. It
     * is a static property, so within one test process it survives between
     * calls and would mask whether the cache layer works at all — every test
     * here has to start from a cold class.
     */
    protected function forgetRequestMemo(): void
    {
        $reflected = new \ReflectionProperty(TransactionResource::class, 'balance');
        $reflected->setAccessible(true);
        $reflected->setValue(null, null);
    }

    public function test_the_balance_badge_is_answered_from_cache_on_a_second_request(): void
    {
        Transaction::factory()->income(1_500_000)->create();

        $this->assertSame(1_500_000, $this->balance());

        $this->forgetRequestMemo();

        $queries = $this->countQueries(fn () => $this->assertSame(1_500_000, $this->balance()));

        $this->assertSame(0, $queries, 'The second request re-ran the aggregate instead of reading the cache.');
    }

    public function test_saving_a_transaction_invalidates_the_balance(): void
    {
        Transaction::factory()->income(1_500_000)->create();

        $this->assertSame(1_500_000, $this->balance());
        $this->forgetRequestMemo();

        Transaction::factory()->expense(500_000)->create();
        $this->forgetRequestMemo();

        $this->assertSame(1_000_000, $this->balance());
    }

    public function test_deleting_a_transaction_invalidates_the_balance(): void
    {
        $transaction = Transaction::factory()->income(1_500_000)->create();

        $this->assertSame(1_500_000, $this->balance());
        $this->forgetRequestMemo();

        $transaction->delete();
        $this->forgetRequestMemo();

        $this->assertSame(0, $this->balance());
    }

    /**
     * The overview card and the badge are two separate aggregates over the same
     * table, so a write has to reach both keys or the sidebar and the stats
     * disagree on the same screen.
     */
    public function test_a_write_invalidates_the_overview_totals_as_well_as_the_badge(): void
    {
        Transaction::factory()->income(1_500_000)->create();

        $this->balance();
        $this->assertNotNull(Cache::get(PanelCache::BALANCE));

        $totals = new \ReflectionMethod(TransactionOverview::class, 'totals');
        $totals->setAccessible(true);
        $this->assertSame(['income' => 1_500_000, 'expense' => 0], $totals->invoke(null));
        $this->assertNotNull(Cache::get(PanelCache::TOTALS));

        Transaction::factory()->expense(500_000)->create();

        $this->assertNull(Cache::get(PanelCache::BALANCE));
        $this->assertNull(Cache::get(PanelCache::TOTALS));
    }

    /**
     * Null is a real answer, not a miss.
     *
     * `Cache::remember()` re-runs its callback whenever the stored value is
     * null, so a figure whose true answer is "none" would pay its query on
     * every page of the panel while the cache still looked like it was working.
     * `PanelCache::remember()` wraps the value in an array to make that a hit.
     *
     * Asserted against the helper directly rather than through a badge. The
     * tariff badge used to be what exercised it and was removed with the tariff
     * screen; nothing cached today returns null, so this is the only thing
     * standing between the wrapper and a silent deletion.
     */
    public function test_a_null_value_is_cached_rather_than_re_queried(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): ?int {
            $calls++;

            return null;
        };

        $this->assertNull(PanelCache::remember('panel:test:null', null, $resolve));
        $this->assertNull(PanelCache::remember('panel:test:null', null, $resolve));

        $this->assertSame(1, $calls, 'A null value fell through the cache and re-ran its query.');
    }

    /**
     * The other half: forgetting by name is the only invalidation there is,
     * because CACHE_STORE is `database` and that store throws on Cache::tags()
     * rather than degrading. A key nobody forgets is a figure that never moves.
     */
    public function test_a_forgotten_key_is_resolved_again(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): int {
            $calls++;

            return 7;
        };

        $this->assertSame(7, PanelCache::remember('panel:test:number', null, $resolve));
        PanelCache::forget('panel:test:number');
        $this->assertSame(7, PanelCache::remember('panel:test:number', null, $resolve));

        $this->assertSame(2, $calls, 'forget() left the old value in place.');
    }
}
