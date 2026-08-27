<?php

namespace App\Models;

use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * One reading of the electricity meter: the figure it started at, the figure it
 * ended at, photographs of the dial behind both, and the amount paid for the
 * period.
 *
 * There is one meter. The feature used to file every reading under a Room and
 * copy its rate out of a versioned electricity_tariffs table, which is the shape
 * a landlord billing several tenants needs; it is now shaped for the tenant
 * recording their own meter, so a reading stands on its own.
 *
 * It no longer computes a bill either. A rate per kWh was the last piece of the
 * landlord shape left: it existed so `usage x rate` could be worked out here,
 * which is a calculation the person paying the bill never performs — they are
 * handed a figure. So `total_amount` is what the row records and the
 * multiplication is gone. The guarantee that made the rate worth storing comes
 * along for free: an amount already paid is a fact about that period, and there
 * is no shared figure left anywhere that a later change could reprice it from.
 *
 * The second model in this project to use medialibrary, and it follows the
 * decision Transaction settled — the private `local` disk. A photograph of a
 * meter carries the room it belongs to and, more often than not, part of the
 * building; it is not something to publish by URL.
 */
class MeterReading extends Model implements HasMedia
{
    /** @use HasFactory<MeterReadingFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity;

    /**
     * Photographs of the dial, one collection per figure.
     *
     * Two collections rather than one collection holding both, because which
     * photograph backs which number is the whole evidentiary point: a disputed
     * bill is settled by comparing the opening figure against the photograph
     * taken when the period opened. A single collection could only express that
     * by upload order, which reordering or deleting one file silently destroys.
     * Here it is `collection_name` on the row, which nothing in the UI can
     * scramble.
     *
     * Named once so the form, the table columns, the infolist entries and the
     * tests cannot drift apart on a string literal — the same reason
     * Transaction::RECEIPTS exists.
     *
     * A collection name that matches nothing registered is not an error: the
     * upload succeeds and the file lands on whichever disk the fall-through
     * picks, which is not the same disk on the Filament path and the addMedia()
     * path. See the Media section of CLAUDE.md.
     */
    public const PHOTOS_START = 'meter-photos-start';

    public const PHOTOS_END = 'meter-photos-end';

    /** The conversion shown in lists and infolists. Never the original. */
    public const THUMBNAIL = 'thumb';

    protected $fillable = [
        'start_kwh',
        'start_read_at',
        'end_kwh',
        'end_read_at',
        'total_amount',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_kwh' => 'integer',
            'end_kwh' => 'integer',
            'total_amount' => 'integer',
            'start_read_at' => 'datetime',
            'end_read_at' => 'datetime',
        ];
    }

    /**
     * Stamps the signed-in user on rows created outside a Filament form, the
     * same way Transaction does. Left null when nobody is signed in rather than
     * guessed at.
     */
    protected static function booted(): void
    {
        static::creating(function (self $reading): void {
            $reading->user_id ??= auth()->id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reading a new one continues from: the latest one, optionally before a
     * given moment and ignoring one row.
     *
     * `id` is the tiebreak, for the same reason CashBook orders by it — two
     * readings sharing a timestamp would otherwise come back in whatever order
     * the engine felt like, and this row becomes the opening figure and the
     * opening moment of the next reading.
     *
     * `$before` and `$excludingId` are both there for the edit screen: without
     * them, reopening a reading offers that same row as its own predecessor,
     * and a reading being corrected would prefill from readings taken after it.
     *
     * Everything here compares `end_read_at`, never `start_read_at`. A reading
     * covers a period and is placed on the timeline by where that period closes;
     * ordering on the opening moment would let a short reading taken inside a
     * long one come back as the later of the two. It also keeps the prefill from
     * being circular — `start_read_at` is what this lookup fills in, so it
     * cannot also be what scopes the lookup.
     */
    public static function previous(?\DateTimeInterface $before = null, ?int $excludingId = null): ?self
    {
        return static::query()
            ->when($before, fn ($query) => $query->where('end_read_at', '<', $before))
            ->when($excludingId, fn ($query) => $query->whereKeyNot($excludingId))
            ->orderByDesc('end_read_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * kWh consumed between the two figures.
     *
     * Deliberately not clamped at zero. The form refuses an end figure below the
     * start one, so a negative value here can only come from a row written
     * outside it — and showing it as negative is how that becomes visible.
     * max(0, …) would render the same broken row as a plausible bill of Rp 0.
     */
    protected function usageKwh(): Attribute
    {
        return Attribute::get(fn (): int => $this->end_kwh - $this->start_kwh);
    }

    /**
     * Photographs live on the private `local` disk, the decision Transaction
     * settled for this project. Every Filament component that renders them sets
     * ->visibility('private') to match; drop it from any one of them and that
     * surface silently renders broken images, because the private disk refuses
     * an unsigned URL before it looks for the file.
     *
     * Both collections are registered with identical rules. They are separate
     * only so that a file says for itself which figure it backs.
     */
    public function registerMediaCollections(): void
    {
        foreach ([self::PHOTOS_START, self::PHOTOS_END] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk('local')
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        }
    }

    /**
     * Generated inline rather than on the queue, for the two reasons given on
     * Transaction: a deploy with no queue worker would produce originals and
     * never a single thumbnail with nothing in the log, and the re-encode drops
     * almost all of the EXIF the phone wrote — GPS coordinates included.
     *
     * Registered once and not pinned to a collection, so it applies to both.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->fit(Fit::Contain, 400, 400)
            ->nonQueued();
    }

    /**
     * Audit trail with an explicit allowlist, the same shape as Transaction.
     * `total_amount` is on it deliberately: it is the money, and now that it is
     * typed rather than computed there is nothing else on the row that would
     * contradict a quiet correction to it.
     *
     * Photographs are a relation, not a column, so LogsActivity cannot see them
     * — their removal is recorded by AppServiceProvider::registerMediaDeletionLogging().
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['start_kwh', 'start_read_at', 'end_kwh', 'end_read_at', 'total_amount', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('meter_reading');
    }
}
