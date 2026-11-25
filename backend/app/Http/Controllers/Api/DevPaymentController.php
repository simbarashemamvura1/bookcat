<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

/** Stands in for the payment provider's webhook while the fake provider is in use. */
class DevPaymentController extends Controller
{
    public function succeed(string $reference, CheckoutService $checkout): JsonResponse
    {
        $result = $checkout->handlePaymentSucceeded($this->latestPayment($reference)->provider_reference);

        return response()->json(['result' => $result->reason, 'orderStatus' => $result->order?->status]);
    }

    public function fail(string $reference, CheckoutService $checkout): JsonResponse
    {
        $result = $checkout->handlePaymentFailed($this->latestPayment($reference)->provider_reference, 'declined');

        return response()->json(['result' => $result->reason, 'orderStatus' => $result->order?->status]);
    }

    private function latestPayment(string $reference): Payment
    {
        return Order::where('reference', strtoupper($reference))->firstOrFail()
            ->payments()->latest('id')->firstOrFail();
    }
}