<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FloorPlanSeat extends Model
{
    public $timestamps = false;

    protected $fillable = ['floor_plan_id', 'seat_key', 'section', 'row_label', 'seat_number'];

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class);
    }

    /**
     * The one place seat keys are built, so the SVG tracer, API and Redis
     * layer can never disagree. Example: makeKey('Orch', 'c', 12) => "ORCH-C-12"
     */
    public static function makeKey(string $section, ?string $row, string|int $number): string
    {
        $clean = fn ($value) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return implode('-', array_filter(
            [$clean($section), $clean($row ?? ''), $clean($number)],
            fn ($part) => $part !== ''
        ));
    }
}