<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\HoldResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait ApiResponses
{
    /** An event the public may see. Anything unpublished or deactivated is a plain 404. */
    protected function publicEvent(string $slug): Event
    {
        $event = Event::where('slug', $slug)->first();

        abort_unless($event && $event->isVisibleToPublic(), 404);

        return $event;
    }

    protected function failure(string $reason, array $problems = []): JsonResponse
    {
        $status = match ($reason) {
            'invalid_seats', 'empty', 'too_many_seats', 'invalid_attendees', 'coupon_invalid' => 422,
            'seats_unavailable', 'seats_not_sellable', 'cart_locked', 'event_not_purchasable', 'expired',
            'cart_changed', 'seat_lost', 'coupon_unavailable' => 409,
            'payment_unavailable' => 503,
            'unknown_payment' => 404,
            default => 500,
        };

        return response()->json(['error' => $reason, 'problems' => $problems], $status);
    }

    /** Validates the JSON body and answers with our own error shape instead of Laravel's. */
    protected function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->json()->all(), $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'error' => 'validation_failed',
                'problems' => $validator->errors()->toArray(),
            ], 422));
        }

        return $validator->validated();
    }

    protected function cart(HoldResult $result): JsonResponse
    {
        return response()->json([
            'state' => $result->state, // none | selecting | paying
            'seats' => $result->seats,
            'remainingMs' => $result->remainingMs,
            'expiresAtMs' => $result->expiresAtMs,
        ])->header('Cache-Control', 'no-store');
    }

    protected function orderPayload(Order $order, bool $withTickets = false): array
    {
        $payload = [
            'reference' => $order->reference,
            'status' => $order->status,
            'currency' => $order->currency,
            'subtotalCents' => $order->subtotal_cents,
            'discountCents' => $order->discount_cents,
            'totalCents' => $order->total_cents,
            'expiresAtMs' => $order->status === Order::STATUS_PENDING ? $order->expires_at?->getTimestampMs() : null,
        ];

        // Tickets (and their QR tokens) are shown only once the order is paid.
        if ($withTickets && $order->status === Order::STATUS_PAID) {
            $payload['tickets'] = $order->tickets()
                ->where('status', Ticket::STATUS_VALID)
                ->with('eventSeat:id,seat_key')
                ->orderBy('id')
                ->get()
                ->map(fn (Ticket $ticket) => [
                    'seat' => $ticket->eventSeat?->seat_key,
                    'name' => $ticket->attendee_name,
                    'isYours' => $ticket->is_buyer_seat,
                    'token' => $ticket->token,
                ])
                ->all();
        }

        return $payload;
    }
}