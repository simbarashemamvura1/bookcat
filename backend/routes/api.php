<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DevPaymentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Middleware\CartToken;
use App\Http\Middleware\JsonApi;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(JsonApi::class)->group(function () {
    // Public reads. The event page never sets a cookie.
    Route::get('events/{slug}', [EventController::class, 'show'])
        ->middleware('throttle:public-read');

    // The seat map reads the cookie (to mark your own seats) but never creates one.
    Route::get('events/{slug}/seats', [EventController::class, 'seats'])
        ->middleware(['throttle:public-read', CartToken::class.':read']);

    // The cart. Only picking a first seat creates the cookie.
    Route::post('events/{slug}/cart/seats', [CartController::class, 'select'])
        ->middleware(['throttle:cart', CartToken::class.':create']);

    Route::middleware(['throttle:cart', CartToken::class.':read'])->group(function () {
        Route::get('events/{slug}/cart', [CartController::class, 'show']);
        Route::post('events/{slug}/cart/seats/remove', [CartController::class, 'remove']);
        Route::delete('events/{slug}/cart', [CartController::class, 'clear']);
    });

    Route::middleware(['throttle:checkout', CartToken::class.':read'])->group(function () {
        Route::post('events/{slug}/checkout', [CheckoutController::class, 'start']);
        Route::post('events/{slug}/checkout/abort', [CheckoutController::class, 'abort']);
    });

    Route::get('orders/{reference}', [CheckoutController::class, 'order'])
        ->middleware(['throttle:orders', CartToken::class.':read']);

    // Stand-in for the payment webhook. These routes do not exist anywhere but local development.
    if (app()->environment('local') && config('bookcat.payments.driver') === 'fake') {
        Route::post('dev/orders/{reference}/pay', [DevPaymentController::class, 'succeed']);
        Route::post('dev/orders/{reference}/fail', [DevPaymentController::class, 'fail']);
    }
});