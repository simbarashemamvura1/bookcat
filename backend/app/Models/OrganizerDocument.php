<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerDocument extends Model
{
    public const TYPE_GOVERNMENT_ID = 'government_id';
    public const TYPE_BUSINESS_REGISTRATION = 'business_registration';
    public const TYPE_VENUE_CONTRACT = 'venue_contract';
    public const TYPE_BANK_DETAILS = 'bank_details';

    // status and review_note are set only by admin review actions.
    protected $fillable = ['type', 'path', 'original_name'];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}