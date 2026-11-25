<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FloorPlan extends Model
{
    public const KIND_TEMPLATE = 'template';
    public const KIND_CUSTOM = 'custom';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_READY = 'ready';

    // venue, owner, kind, status and ready_* are set server-side only.
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['ready_at' => 'datetime'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'owner_organizer_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(FloorPlanSeat::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Floor plans an organizer may pick for an event: ready ones that are
     * either library templates or that organizer's own custom builds.
     * This is the ownership check from the roles matrix (section 0b) for floor plans.
     */
    public function scopeSelectableBy(Builder $query, Organizer $organizer): Builder
    {
        return $query->where('status', self::STATUS_READY)
            ->where(function (Builder $q) use ($organizer) {
                $q->whereNull('owner_organizer_id')
                    ->orWhere('owner_organizer_id', $organizer->id);
            });
    }
}