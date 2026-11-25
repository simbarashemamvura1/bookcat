<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\HoldResult;
use App\Services\SeatHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    use ApiResponses;

    /** The buyer pressed Pay. The seat list comes from their cart, never from this request. */
    public function start(Request $request, string $slug, CheckoutService $checkout): JsonResponse
    {
        $event = $this->publicEvent($slug);

        $data = $this->validated($request, [
            'buyer_email' => ['required', 'string', 'email:rfc', 'max:190'],
            'own_seat' => ['required', 'string', 'max:64'],
            'attendees' => ['required', 'array', 'min:1', 'max:'.config('bookcat.holds.max_seats')],
            'attendees.*' => ['required', 'array'],
            'attendees.*.name' => ['required', 'string', 'max:120'],
            'attendees.*.email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'coupon' => ['nullable', 'string', 'max:32'],
        ]);

        $token = $request->attributes->get('cart_token');
        if (! $token) {
            return $this->failure('expired'); // no cookie, so no cart
        }

        $result = $checkout->start(
            $event,
            $token,
            $data['buyer_email'],
            $data['attendees'],
            $data['own_seat'],
            $data['coupon'] ?? null,
        );

        if (! $result->ok) {
            return $this->failure($result->reason, $result->problems);
        }

        return response()->json([
            'order' => $this->orderPayload($result->order),
            'payment' => $result->payment
                ? ['provider' => $result->payment->provider, 'status' => $result->payment->status]
                : null,
            'clientSecret' => $result->clientSecret,
        ])->header('Cache-Control', 'no-store');
    }

    /** Back out of paying: cancels the pending order and unlocks the cart. */
    public function abort(Request $request, string $slug, CheckoutService $checkout): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $token = $request->attributes->get('cart_token');

        return $this->cart($token ? $checkout->abort($event, $token) : new HoldResult(true));
    }

    /** Order status for the confirmation page. Only the browser that started the checkout can read it. */
    public function order(Request $request, string $reference, SeatHoldService $holds): JsonResponse
    {
        $token = $request->attributes->get('cart_token');
        $order = Order::where('reference', strtoupper($reference))->first();

        // The same 404 whether the order does not exist or belongs to someone else.
        abort_unless(
            $order && $token && $order->cart_token_hash && hash_equals($order->cart_token_hash, hash('sha256', $token)),
            404,
        );

        if ($order->status === Order::STATUS_PAID) {
            // The sale is final, so the buyer's seat locks are no longer needed.
            $holds->release($order->event, $token);
        }

        return response()->json(['order' => $this->orderPayload($order->refresh(), true)])
            ->header('Cache-Control', 'no-store');
    }
}