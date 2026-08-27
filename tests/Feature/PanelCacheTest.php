<?php

namespace Tests\Feature;

use App\Filament\Resources\ElectricityTariffs\ElectricityTariffResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Transactions\Widgets\TransactionOverview;
use App\Models\ElectricityTariff;
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
     * The two resources hold their per-request figure in a static property, and
     * a static outlives a test — RefreshDatabase rebuilds the schema and the
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

    protected function currentRate(): ?int
    {
        $method = new \ReflectionMethod(ElectricityTariffResource::class, 'currentRate');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    /**
     * Clears the per-request statics the two resources hold alongside the
     * cache. They are static properties, so within one test process they
     * survive between calls and would mask whether the cache layer works at
     * all — every test here has to start from a cold class.
     */
    protected function forgetRequestMemo(): void
    {
        foreach ([[TransactionResource::class, 'balance', null]] as [$class, $property, $value]) {
            $reflected = new \ReflectionProperty($class, $property);
            $reflected->setAccessible(true);
            $reflected->setValue(null, $value);
        }

        foreach ([['currentRate', null], ['currentRateResolved', false]] as [$property, $value]) {
            $reflected = new \ReflectionProperty(ElectricityTariffResource::class, $property);
            $reflected->setAccessible(true);
            $reflected->setValue(null, $value);
        }
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
     * An empty tariff table is a real answer, not a miss. Cache::remember()
     * would re-run the query on every call for a null value, which is why
     * PanelCache wraps it — and why the failure would otherwise be invisible.
     */
    public function test_an_unset_tariff_is_cached_rather_than_re_queried(): void
    {
        $this->assertNull($this->currentRate());

        $this->forgetRequestMemo();

        $queries = $this->countQueries(fn () => $this->assertNull($this->currentRate()));

        $this->assertSame(0, $queries, 'A null rate fell through the cache and hit the database again.');
    }

    public function test_saving_a_tariff_invalidates_the_rate_badge(): void
    {
        ElectricityTariff::factory()->rate(1_500, now()->subMonth()->toDateString())->create();

        $this->assertSame(1_500, $this->currentRate());
        $this->forgetRequestMemo();

        ElectricityTariff::factory()->rate(2_000, now()->toDateString())->create();
        $this->forgetRequestMemo();

        $this->assertSame(2_000, $this->currentRate());
    }

    /**
     * The line the cache must not cross.
     *
     * ElectricityTariff::currentRate() is what MeterReadingForm defaults its
     * rate field from, and that figure is copied onto the reading and billed
     * from there — see docs/listrik-kost.md. Caching it would make a stale
     * badge into a wrong tenant bill, permanently, so the model method stays
     * live no matter what the badge has stored.
     */
    public function test_the_model_rate_is_never_served_from_the_badge_cache(): void
    {
        ElectricityTariff::factory()->rate(1_500, now()->subMonth()->toDateString())->create();

        $this->currentRate();

        Cache::put(PanelCache::RATE, ['value' => 999], 60);

        $this->assertSame(1_500, ElectricityTariff::currentRate());
    }
}
