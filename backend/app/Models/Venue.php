<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // owner, creator and status are set server-side only.
    protected $fillable = ['name', 'address_line', 'city', 'region', 'country', 'postal_code', 'timezone'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'owner_organizer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'created_by');
    }

    public function floorPlans(): HasMany
    {
        return $this->hasMany(FloorPlan::class);
    }

    public function isPlatformWide(): bool
    {
        return $this->owner_organizer_id === null;
    }
}