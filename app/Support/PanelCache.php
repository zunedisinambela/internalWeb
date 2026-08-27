<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The cross-request cache for figures the panel shows on every page.
 *
 * The panel sits at the root path, so its sidebar renders on every screen in
 * the app — /activities and /log-viewer included. Each navigation badge there
 * costs an aggregate query, and none of those queries has anything to do with
 * the page being opened. That is what this caches: not HTML, and not anything
 * a policy gates.
 *
 * **Why not a full-page cache.** Three things in this panel are request-scoped
 * in a way a cached response cannot carry: Shield checks permissions per user,
 * so one user's HTML is not another's; `RecordVisit` sits in the panel's own
 * middleware stack, so a response served from cache is a page view that never
 * reaches /visits; and Livewire bakes a CSRF token into the markup, which goes
 * stale with the page. A cache that skips the audit trail is not a speed-up,
 * it is a hole — see docs/monitoring.md.
 *
 * **Keys are explicit because the store has no tags.** CACHE_STORE is
 * `database`, and only the array/Redis/Memcached stores support tagging, so
 * `Cache::tags(...)->flush()` throws here rather than degrading. Every cached
 * figure therefore gets a named constant and is forgotten by name from the
 * model that invalidates it.
 *
 * That store is shared with the ExportCashBook unique-job lock, which is the
 * reason CACHE_STORE has to stay a driver every process can see. Nothing here
 * changes that requirement; it inherits it.
 */
final class PanelCache
{
    /**
     * The cash book balance shown on the Keuangan navigation badge.
     */
    public const BALANCE = 'panel:transactions:balance';

    /**
     * Income, expense and balance in one row, for TransactionOverview.
     */
    public const TOTALS = 'panel:transactions:totals';

    /**
     * The rupiah-per-kWh figure on the Tarif navigation badge. Presentation
     * only — the rate a reading is billed at is read live, never from here.
     */
    public const RATE = 'panel:tariffs:current-rate';

    /**
     * Remember a value, treating null as a real answer.
     *
     * `Cache::remember()` cannot: it re-runs the callback whenever the stored
     * value is null, so an empty tariff table would pay its query on every
     * page of the panel and the cache would look like it was working. Wrapping
     * the value in an array makes "no tariff has been set" a hit.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @param  int|null  $ttl  seconds, or null to keep until forgotten
     * @return TValue
     */
    public static function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $wrapped = fn (): array => ['value' => $callback()];

        $entry = $ttl === null
            ? Cache::rememberForever($key, $wrapped)
            : Cache::remember($key, $ttl, $wrapped);

        return $entry['value'];
    }

    /**
     * Drop cached figures by name. Called from model events, so it runs inside
     * whatever transaction the write is in — a rolled-back save forgets a key
     * that did not need forgetting, which costs one query and is the safe
     * direction to be wrong in.
     */
    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * How long the current-rate badge may be stale.
     *
     * TODO — this is the one decision here that needs a domain answer, because
     * the tariff table is the only cached source that can change **without any
     * model event firing**. A row dated in the future is how a raise is
     * scheduled (see docs/listrik-kost.md), so at midnight on its
     * `effective_from` the correct badge changes while nothing is written and
     * nothing is dispatched. Every other key on this class is invalidated by a
     * save or a delete and can be cached indefinitely.
     *
     * Three answers, and they are not equivalent:
     *
     *   - `return null;` — cache until forgotten. Fastest, and wrong from
     *     midnight until somebody next edits a tariff. Nobody is prompted to,
     *     because the schedule was already entered.
     *   - `return (int) Carbon::now()->diffInSeconds(Carbon::tomorrow());`
     *     — expires exactly when a scheduled raise takes effect. One write per
     *     day, correct at the boundary, and it depends on the clock being the
     *     panel's own (APP_TIMEZONE is Asia/Jakarta and timestamps are stored
     *     in WIB, so `Carbon::tomorrow()` is already the right midnight).
     *   - `return 300;` — a flat window. Self-healing without reasoning about
     *     dates, at the cost of the badge being wrong for up to five minutes
     *     after any change this class failed to notice.
     *
     * The trade-off is how wrong the sidebar is allowed to be about a price
     * that is not billed from here anyway. Currently the first: no expiry.
     */
    public static function rateTtl(): ?int
    {
        return null;
    }
}
