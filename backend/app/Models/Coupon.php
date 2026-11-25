<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Coupon extends Model
{
    public const TYPE_FLAT = 'flat';
    public const TYPE_PERCENT = 'percent';
    public const USAGE_ONE_TIME = 'one_time';
    public const USAGE_MULTIPLE = 'multiple_time';

    // Event, creator, counters and active flag are set server-side only.
    protected $fillable = ['code', 'discount_type', 'discount_value', 'usage_type', 'max_redemptions', 'expires_at'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Coupon $coupon) {
            if ($coupon->usage_type === self::USAGE_ONE_TIME) {
                $coupon->max_redemptions = 1;
            }
        });
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtoupper(trim($value)));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Ownership check (roles matrix 0b): an organizer only ever sees coupons on their own events. */
    public function scopeOwnedBy(Builder $query, Organizer $organizer): Builder
    {
        return $query->whereHas('event', fn ($q) => $q->where('organizer_id', $organizer->id));
    }

    /**
     * Atomically use up one redemption. Done in a single UPDATE so two buyers racing for the
     * last use of a one-time coupon can never both succeed.
     */
    public function claim(): bool
    {
        $claimed = DB::table('coupons')
            ->where('id', $this->id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions_count', '<', 'max_redemptions'))
            ->increment('redemptions_count');

        if ($claimed === 1) {
            $this->refresh();
        }

        return $claimed === 1;
    }

    /** Give a redemption back (payment failed or the hold expired). */
    public function release(): void
    {
        DB::table('coupons')
            ->where('id', $this->id)
            ->where('redemptions_count', '>', 0)
            ->decrement('redemptions_count');

        $this->refresh();
    }
}