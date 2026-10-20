<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeat;
use App\Services\SeatHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class EventController extends Controller
{
    use ApiResponses;

    public function show(string $slug): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $event->loadMissing('floorPlan.venue', 'organizer');
        $venue = $event->floorPlan?->venue;

        return response()->json(['event' => [
            'slug' => $event->slug,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'restrictions' => $event->restrictions,
            'refundPolicy' => $event->refund_policy,
            'startsAt' => $event->starts_at?->toIso8601String(),
            'endsAt' => $event->ends_at?->toIso8601String(),
            'currency' => $event->currency,
            'isFree' => $event->is_free,
            'organizer' => ['name' => $event->organizer->business_name],
            'venue' => $venue ? [
                'name' => $venue->name,
                'addressLine' => $venue->address_line,
                'city' => $venue->city,
                'region' => $venue->region,
                'country' => $venue->country,
                'timezone' => $venue->timezone,
            ] : null,
            'priceFromCents' => $event->seats()->whereNotNull('price_cents')->min('price_cents'),
            'priceToCents' => $event->seats()->whereNotNull('price_cents')->max('price_cents'),
            'saleState' => $this->saleState($event),
        ]]);
    }

    /**
     * Every seat with what the buyer needs to draw it. Seats look the same to the public whether
     * they are sold or blocked by the organizer, so the reason is never revealed.
     */
    public function seats(Request $request, string $slug, SeatHoldService $holds): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $token = $request->attributes->get('cart_token');

        $mine = $token ? array_flip($holds->status($event, $token)->seats) : [];
        $held = $holds->availability($event)['held'];

        $seats = $event->seats()
            ->orderBy('id')
            ->get(['seat_key', 'section', 'row_label', 'seat_number', 'price_cents', 'status'])
            ->map(function ($seat) use ($mine, $held) {
                $key = $seat->seat_key;
                $until = null;

                if (isset($mine[$key])) {
                    $state = 'mine';
                } elseif ($seat->status !== EventSeat::STATUS_AVAILABLE || $seat->price_cents === null) {
                    $state = 'unavailable';
                } elseif (isset($held[$key])) {
                    $state = 'held';
                    $until = $held[$key];
                } else {
                    $state = 'available';
                }

                return [
                    'key' => $key,
                    'section' => $seat->section,
                    'row' => $seat->row_label,
                    'number' => $seat->seat_number,
                    'priceCents' => $seat->price_cents,
                    'state' => $state,
                    'heldUntilMs' => $until,
                ];
            })
            ->all();

        // Hold expiry times come from the Redis clock, so the client compares them with this one.
        [$sec, $usec] = Redis::connection()->time();

        return response()->json([
            'serverTimeMs' => ((int) $sec) * 1000 + intdiv((int) $usec, 1000),
            'seats' => $seats,
        ])->header('Cache-Control', 'no-store');
    }

    private function saleState(Event $event): string
    {
        if ($event->sales_status === Event::SALES_SOLD_OUT) {
            return 'sold_out';
        }
        if ($event->sales_status === Event::SALES_FROZEN) {
            return 'paused'; // the same wording whoever paused it
        }

        return $event->isPurchasable() ? 'on_sale' : 'countdown';
    }
}