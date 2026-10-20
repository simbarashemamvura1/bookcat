<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\HoldResult;
use App\Services\SeatHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly SeatHoldService $holds,
        private readonly CheckoutService $checkout,
    ) {
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $token = $request->attributes->get('cart_token');

        return $this->cart($token ? $this->holds->status($event, $token) : new HoldResult(true));
    }

    public function select(Request $request, string $slug): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $data = $this->validated($request, $this->seatRules());

        $result = $this->holds->select($event, $request->attributes->get('cart_token'), $data['seats']);

        return $result->ok ? $this->cart($result) : $this->failure($result->reason, $result->conflicts);
    }

    public function remove(Request $request, string $slug): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $data = $this->validated($request, $this->seatRules());
        $token = $request->attributes->get('cart_token');

        if (! $token) {
            return $this->cart(new HoldResult(true));
        }

        $result = $this->holds->deselect($event, $token, $data['seats']);

        return $result->ok ? $this->cart($result) : $this->failure($result->reason, $result->conflicts);
    }

    /** The buyer abandons the cart. */
    public function clear(Request $request, string $slug): JsonResponse
    {
        $event = $this->publicEvent($slug);
        $token = $request->attributes->get('cart_token');

        if ($token) {
            // Cancel a pending order first (this also gives its coupon back), so seats are
            // never freed underneath a live payment.
            $this->checkout->abort($event, $token);
            $this->holds->release($event, $token);
        }

        return $this->cart(new HoldResult(true));
    }

    private function seatRules(): array
    {
        return [
            'seats' => ['required', 'array', 'min:1', 'max:'.config('bookcat.holds.max_seats')],
            'seats.*' => ['required', 'string', 'max:64'],
        ];
    }
}