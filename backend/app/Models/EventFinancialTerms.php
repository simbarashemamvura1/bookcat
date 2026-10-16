<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class EventFinancialTerms extends Model
{
    protected $table = 'event_financial_terms';

    // Overrides are an admin action behind the override_financial_terms permission,
    // so nothing here is mass-assignable.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'reserve_enabled' => 'boolean',
            'overridden_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public static function defaultsFor(string $currency): array
    {
        $schedule = config("bookcat.fees.{$currency}");

        if (! $schedule) {
            throw new RuntimeException("No fee schedule configured for {$currency}.");
        }

        return $schedule;
    }

    /** Copy the platform defaults onto the event. Fully free events carry no fees at all. */
    public static function createDefaultsFor(Event $event): self
    {
        $defaults = self::defaultsFor($event->currency);
        $free = $event->is_free;

        $terms = new self();
        $terms->forceFill([
            'event_id' => $event->id,
            'creation_fee_cents' => $free ? 0 : $defaults['creation_fee_cents'],
            'ticket_fee_flat_cents' => $free ? 0 : $defaults['ticket_fee_flat_cents'],
            'ticket_fee_bps' => $free ? 0 : $defaults['ticket_fee_bps'],
            'processing_fee_bps' => $free ? 0 : $defaults['processing_fee_bps'],
            'minimum_charge_cents' => $free ? 0 : $defaults['minimum_charge_cents'],
            'payout_delay_business_days' => $defaults['payout_delay_business_days'],
        ])->save();

        return $terms;
    }
}