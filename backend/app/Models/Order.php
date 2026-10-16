<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_COMP = 'comp';

    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED = 'refunded';

    // Only what the buyer types. Type, event, status and money are set server-side.
    protected $fillable = ['buyer_name', 'buyer_email'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->reference ??= self::newReference();
        });
    }

    /** Lowercased on write, so linking guest orders to an account is a plain equality match. */
    protected function buyerEmail(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    private static function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I

        do {
            $reference = 'BK-';
            for ($i = 0; $i < 8; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendeeAccount(): BelongsTo
    {
        return $this->belongsTo(AttendeeAccount::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Totals always come from the tickets, so an order can never disagree with its own lines. */
    public function recalculateTotals(): void
    {
        $this->forceFill([
            'subtotal_cents' => (int) $this->tickets()->sum('list_price_cents'),
            'discount_cents' => (int) $this->tickets()->sum('discount_cents'),
            'total_cents' => (int) $this->tickets()->sum('price_paid_cents'),
        ])->save();
    }
}