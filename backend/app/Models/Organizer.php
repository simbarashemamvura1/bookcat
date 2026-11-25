<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organizer extends Model
{
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Review fields, origin and flags are NOT mass-assignable: only server-side
    // admin actions may set them, never a request payload.
    protected $fillable = ['business_name', 'slug', 'bio', 'logo_path', 'contact_phone'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OrganizerDocument::class);
    }

    public function isApproved(): bool
    {
        return $this->application_status === self::STATUS_APPROVED;
    }
}