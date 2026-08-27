<?php

namespace App\Models;

use Database\Factories\FreeItemRedemptionFactory;
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
 * One handover of the free items a customer has earned.
 *
 * The bonus itself is derived and always will be: Customer::$free_quantity
 * divides the customer's total item count by Sale::FREE_ITEM_THRESHOLD, so it
 * cannot disagree with the orders it came from. What no column could answer
 * before this model existed is the other half of the question — whether the
 * customer has actually *collected* it, when, and by which parcel.
 *
 * That half is a fact about the world rather than arithmetic over the sales, so
 * it is recorded rather than derived. The two are kept apart deliberately: the
 * earned figure moves when a quantity is corrected, and this row does not move
 * at all, because a handover that happened is not undone by an edit to an order.
 *
 * `quantity` counts bonus items, not products, and touches no money anywhere.
 * Whether the free item is still paid for to Oriflame remains undecided — see
 * Sale::$free_quantity — so nothing here reaches `marketing_price` or a margin.
 */
class FreeItemRedemption extends Model implements HasMedia
{
    /** @use HasFactory<FreeItemRedemptionFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity;

    /**
     * The courier's resi, photographed.
     *
     * The same collection name Sale uses, on a different model, which is what
     * keeps the two apart — media rows are keyed by morph, so `shipping-proofs`
     * on a redemption and `shipping-proofs` on a sale never meet. Named to match
     * rather than to differ, because it is evidence of the same kind of event.
     *
     * It sits beside `tracking_number` rather than replacing it: a number can be
     * searched and pasted into the courier's site, a photograph cannot, and a
     * photograph is what survives when the number was never written down.
     */
    public const SHIPPING_PROOFS = 'shipping-proofs';

    /** The conversion shown in tables and infolists. Never the original. */
    public const THUMBNAIL = 'thumb';

    protected $fillable = [
        'customer_id',
        'redeemed_at',
        'quantity',
        'tracking_number',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }

    /**
     * Stamps the signed-in user, the same way Sale, Transaction and
     * MeterReading do. Left null when nobody is signed in rather than guessed
     * at — an unattributed row is honest, a guessed one is not.
     */
    protected static function booted(): void
    {
        static::creating(function (self $redemption): void {
            $redemption->user_id ??= auth()->id();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Private disk, for the reason Sale's attachments are: a resi carries the
     * customer's home address, which is the same detail `customers.address`
     * holds as text except that a photograph cannot be redacted or corrected,
     * only shown or withheld.
     *
     * Every Filament component rendering one sets ->visibility('private') to
     * match; drop it from any of them and that surface renders broken images
     * with nothing in the log. See the Media section of CLAUDE.md.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SHIPPING_PROOFS)
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Inline rather than queued, the decision Transaction and Sale both make: a
     * deploy with no queue worker would produce originals and never a single
     * thumbnail, with nothing in the log to say so. The re-encode also drops
     * almost all of the EXIF, which matters here because a resi is often
     * photographed at the counter. See Gotchas.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->fit(Fit::Contain, 400, 400)
            ->nonQueued();
    }

    /**
     * Its own log name rather than `sale` or `customer`.
     *
     * Filtering the activity log for "when did somebody collect a free item" is
     * a different question from "what did this customer buy", and folding it
     * into either log would mean reading past one to answer the other. The date
     * and the count are the whole record of a handover, so both are on the
     * allowlist — a redemption backdated after the fact has to stay explicable.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'redeemed_at', 'quantity', 'tracking_number', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('free_item_redemption');
    }
}
