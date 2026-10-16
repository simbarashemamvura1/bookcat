<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSeat extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_SOLD = 'sold';

    // Status changes go through explicit actions (sale, organizer block), not mass assignment.
    protected $fillable = ['price_cents', 'block_note'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}