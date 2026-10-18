<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUND_PENDING = 'refund_pending';
    public const STATUS_REFUNDED = 'refunded';

    // Payments are created and changed only by the checkout service, never from a request.
    protected $fillable = [];

    protected function casts(): array
    {
        return ['succeeded_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}