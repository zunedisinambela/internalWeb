<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Support\PanelCache;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One line of the cash book: money in or money out, with its receipts attached.
 *
 * This is the first model in the project to use medialibrary, so it settles the
 * disk question that the Media section of CLAUDE.md left open — see
 * registerMediaCollections() below.
 */
class Transaction extends Model implements HasMedia
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity;

    /**
     * The collection every receipt image lands in. Named once here so the form,
     * the table column and the tests cannot drift apart on a string literal.
     */
    public const RECEIPTS = 'receipts';

    /**
     * The conversion shown in lists and infolists. Never the original.
     */
    public const THUMBNAIL = 'thumb';

    protected $fillable = [
        'type',
        'amount',
        'description',
        'occurred_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Stamps the signed-in user on rows created outside a Filament form.
     *
     * The resource sets user_id itself, so this only catches the other paths —
     * a console command, a seeder, tinker. Left null when nobody is signed in
     * rather than guessed at: an unattributed row is honest, a wrong one is not.
     */
    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->user_id ??= auth()->id();
        });

        // The balance badge and the overview stats are aggregates over this
        // whole table, so any row that lands, moves or leaves invalidates both.
        // Listed as two events rather than one: `saved` misses a deletion,
        // and `deleted` alone misses an amount being corrected. There is no
        // SoftDeletes here, so `restored` has nothing to fire on.
        //
        // This is what makes it safe to cache the two figures indefinitely —
        // neither has a clock in it, unlike the tariff rate. See PanelCache.
        $flush = static function (): void {
            PanelCache::forget(PanelCache::BALANCE, PanelCache::TOTALS);
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Receipts live on the private `local` disk, not on `public`.
     *
     * This is the disk decision CLAUDE.md flagged as the medialibrary equivalent
     * of the timezone choice — moving files later means rewriting the `disk`
     * column on every row and relocating the files, so it is settled before the
     * first row exists rather than after.
     *
     * `public` resolves to storage/app/public behind the public/storage symlink,
     * where files are fetched straight off the filesystem by URL: no role check,
     * no Shield policy, no activity_log entry. A receipt photograph carries
     * account numbers, addresses and amounts, so publishing it by URL would be a
     * read surface that sidesteps the access control every other screen in this
     * panel enforces — the same shape of hole as an ungated /log-viewer.
     *
     * The `local` disk has no `visibility` key in config/filesystems.php, so
     * Laravel treats it as private and its `serve => true` route rejects any
     * request without a valid signature. Filament's components ask for a signed,
     * expiring URL whenever the field is marked ->visibility('private'), which
     * is why every one of them sets it.
     *
     * Signed URLs are not the same as a policy check: within their lifetime the
     * link works for whoever holds it. That is a deliberate step up from the
     * public disk rather than the end of the road — the full fix is a controller
     * that calls authorize() and streams the file, and it is only worth building
     * once these files leave the panel.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RECEIPTS)
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * The thumbnail is generated inline, not on the queue.
     *
     * Conversions are queued by default, and QUEUE_CONNECTION is `database`, so
     * a deploy without a worker would upload originals happily and never render
     * a single thumbnail — with nothing in the log, because nothing failed. A
     * receipt thumbnail is small enough that doing it in the request is cheaper
     * than that failure mode.
     *
     * It also has a second job. The original keeps whatever EXIF the phone wrote
     * into it, GPS coordinates included; this conversion is re-encoded and drops
     * almost all of it. Showing the thumbnail everywhere means the original is
     * only ever reached through a deliberate, signed request.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->fit(Fit::Contain, 400, 400)
            ->nonQueued();
    }

    /**
     * The running balance, in whole rupiah, over the given query.
     *
     * Expressed as one aggregate rather than two so the caller cannot pair a
     * filtered income total with an unfiltered expense one.
     */
    public static function balance(?Builder $query = null): int
    {
        $query ??= static::query();

        return (int) $query->clone()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE -amount END), 0) AS balance',
                [TransactionType::Income->value],
            )
            ->value('balance');
    }

    /**
     * Audit trail. Attributes are listed explicitly for the same reason they are
     * on User: an allowlist cannot leak a column added later.
     *
     * Receipts are not covered here — they are a relation, not a column, so
     * LogsActivity cannot see them at all. Their removal is audited separately
     * by AppServiceProvider::registerReceiptDeletionLogging(), the same split
     * LogRoleChange makes for roles.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'amount', 'description', 'occurred_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('transaction');
    }
}
