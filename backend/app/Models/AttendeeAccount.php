<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AttendeeAccount extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'marketing_consent_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Lowercased on write, so Sam@x.com and sam@x.com can never be two accounts. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Attach earlier guest orders to this account by email. Requires a VERIFIED email,
     * otherwise anyone could claim someone else's tickets by registering with their address.
     * Comp orders are excluded: their buyer_email is the organizer's, not an attendee's.
     *
     * Call this when the member verifies their email. Returns how many orders were linked.
     */
    public function linkGuestOrders(): int
    {
        if ($this->email_verified_at === null) {
            return 0;
        }

        return Order::whereNull('attendee_account_id')
            ->where('type', Order::TYPE_PURCHASE)
            ->where('buyer_email', $this->email)
            ->update(['attendee_account_id' => $this->id]);
    }
}