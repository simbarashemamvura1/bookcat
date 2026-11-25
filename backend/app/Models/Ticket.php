<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Ticket extends Model
{
    public const KIND_PAID = 'paid';
    public const KIND_COMP = 'comp';

    // pending = created at checkout, not yet paid. Only 'valid' tickets own a seat
    // (the partial unique index) and only 'valid' tickets may ever be delivered.
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const ADMISSION_SEATED = 'seated';
    public const ADMISSION_STANDING = 'standing';

    // Only what the buyer supplies per seat. Seat, prices, fees and status are set server-side.
    protected $fillable = ['attendee_name', 'attendee_email'];

    protected function casts(): array
    {
        return [
            'is_buyer_seat' => 'boolean',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            $ticket->token ??= Str::random(40);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventSeat(): BelongsTo
    {
        return $this->belongsTo(EventSeat::class);
    }

    /** What the organizer keeps from this ticket: paid minus both fees. */
    public function getOrganizerNetCentsAttribute(): int
    {
        return $this->price_paid_cents - $this->bookcat_fee_cents - $this->processing_fee_cents;
    }
}