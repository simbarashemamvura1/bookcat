<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class Event extends Model
{
    public const REVIEW_DRAFT = 'draft';
    public const REVIEW_SUBMITTED = 'submitted';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    public const SALES_OPEN = 'open';
    public const SALES_FROZEN = 'frozen';
    public const SALES_SOLD_OUT = 'sold_out';

    // Everything else (ownership, review, activation, toggles, freeze) is set
    // server-side through explicit actions, never from a request payload.
    protected $fillable = [
        'title', 'description', 'category', 'restrictions', 'refund_policy',
        'starts_at', 'ends_at', 'currency', 'is_free',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_free' => 'boolean',
            'reviewed_at' => 'datetime',
            'creation_fee_paid_at' => 'datetime',
            'activated_at' => 'datetime',
            'made_public_at' => 'datetime',
            'made_bookable_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function financialTerms(): HasOne
    {
        return $this->hasOne(EventFinancialTerms::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(EventSeat::class);
    }

    public function getVenueAttribute(): ?Venue
    {
        return $this->floorPlan?->venue;
    }

    /** The ownership check from the roles matrix (0b): organizers only ever see their own events. */
    public function scopeOwnedBy(Builder $query, Organizer $organizer): Builder
    {
        return $query->where('organizer_id', $organizer->id);
    }

    public static function generateSlug(string $title): string
    {
        return Str::slug($title).'-'.Str::lower(Str::random(6));
    }

    public function isApproved(): bool
    {
        return $this->review_status === self::REVIEW_APPROVED;
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /** Free events pay no creation fee, so there is nothing to wait for. */
    public function creationFeeSettled(): bool
    {
        return $this->is_free || $this->creation_fee_paid_at !== null;
    }

    /** Make Public: Approved + fee settled + Admin-Activated. Not gated by floor plan status. */
    public function canBeMadePublic(): bool
    {
        return $this->isApproved() && $this->creationFeeSettled() && $this->isActivated();
    }

    /** Make Bookable: everything Public needs, plus a ready floor plan. */
    public function canBeMadeBookable(): bool
    {
        return $this->canBeMadePublic() && $this->floorPlan?->isReady() === true;
    }

    /**
     * Visible to visitors. Re-evaluates the gates every time, so an Admin
     * deactivating the event hides it even if the organizer's toggle is still on.
     */
    public function isVisibleToPublic(): bool
    {
        return $this->made_public_at !== null && $this->canBeMadePublic();
    }

    public function isPurchasable(): bool
    {
        return $this->made_bookable_at !== null
            && $this->sales_status === self::SALES_OPEN
            && $this->canBeMadeBookable();
    }

    /**
     * Copy the floor plan's seats into event_seats (the per-event snapshot).
     *
     * - The floor plan must be ready: a custom plan has no seats until it is built,
     *   so for those events the snapshot happens after Admin marks it ready.
     * - Re-snapshotting is only allowed while the event is a draft. After that the
     *   layout is locked, and seats with sales can never be moved or deleted.
     */
    public function snapshotSeats(): int
    {
        $plan = $this->floorPlan;

        if (! $plan || ! $plan->isReady()) {
            throw new LogicException('The floor plan must be ready before its seats can be snapshotted.');
        }

        if ($this->seats()->exists() && $this->review_status !== self::REVIEW_DRAFT) {
            throw new LogicException('Seats were already snapshotted and the layout is locked after submission.');
        }

        return DB::transaction(function () use ($plan) {
            $this->seats()->delete();

            $now = now();
            $rows = $plan->seats()->get()->map(fn (FloorPlanSeat $seat) => [
                'event_id' => $this->id,
                'floor_plan_seat_id' => $seat->id,
                'seat_key' => $seat->seat_key,
                'section' => $seat->section,
                'row_label' => $seat->row_label,
                'seat_number' => $seat->seat_number,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                EventSeat::insert($chunk);
            }

            return count($rows);
        });
    }
}