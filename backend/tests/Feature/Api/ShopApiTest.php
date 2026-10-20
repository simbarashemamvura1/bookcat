<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CartToken;
use App\Models\Event;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\SeatHoldService;
use Database\Seeders\DemoEventSeeder;
use Database\Seeders\DemoFloorPlanSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class ShopApiTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private string $base;

    /** RefreshDatabase wipes whatever database is configured. Refuse to touch anything but the test one. */
    protected function beforeRefreshingDatabase(): void
    {
        $name = DB::connection()->getDatabaseName();

        if ($name !== 'bookcat_test') {
            throw new RuntimeException("Refusing to run: tests point at '{$name}', not 'bookcat_test'.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('5', (string) config('database.redis.default.database'), 'Tests must use Redis database 5.');
        Redis::connection()->flushdb();

        foreach ([PermissionSeeder::class, SuperAdminSeeder::class, DemoFloorPlanSeeder::class, DemoEventSeeder::class, DemoOrderSeeder::class] as $seeder) {
            $this->seed($seeder);
        }

        $this->event = Event::where('title', 'Demo Concert')->firstOrFail();
        $this->base = '/api/v1/events/'.$this->event->slug;
    }

    // ---------------------------------------------------------------- helpers

    /** A browser's cart token, minted the same way the service does. */
    private function browser(): string
    {
        return app(SeatHoldService::class)->newToken();
    }

       /** The next request is sent by the browser that owns this token. */
    private function asBuyer(string $token): static
    {
        // JSON test requests drop cookies unless credentials are switched on.
        return $this->withCredentials()->withUnencryptedCookie(CartToken::COOKIE, $token);
    }

    private function people(array $seats): array
    {
        $out = [];

        foreach (array_values($seats) as $i => $key) {
            $out[$key] = ['name' => 'Person '.($i + 1), 'email' => $i === 0 ? '' : 'p'.($i + 1).'@example.test'];
        }

        return $out;
    }

    private function checkoutBody(array $seats, array $extra = []): array
    {
        return array_merge([
            'buyer_email' => 'Buyer@Example.test',
            'own_seat' => $seats[0],
            'attendees' => $this->people($seats),
        ], $extra);
    }

    // ---------------------------------------------------------------- event pages

    public function test_event_page_shows_public_facts_only(): void
    {
        $response = $this->getJson($this->base)
            ->assertOk()
            ->assertJsonPath('event.title', 'Demo Concert')
            ->assertJsonPath('event.currency', 'CAD')
            ->assertJsonPath('event.saleState', 'on_sale')
            ->assertJsonPath('event.priceFromCents', 3000)
            ->assertJsonPath('event.priceToCents', 5000)
            ->assertCookieMissing(CartToken::COOKIE);

        foreach (['fee_cents', 'reviewed', 'activated', 'organizer_id', 'created_by', 'cart_token'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_unpublished_or_deactivated_events_are_404(): void
    {
        $this->event->forceFill(['made_public_at' => null])->save();
        $this->getJson($this->base)->assertNotFound();
        $this->getJson($this->base.'/seats')->assertNotFound();

        $this->event->forceFill(['made_public_at' => now(), 'activated_at' => null])->save();
        $this->getJson($this->base)->assertNotFound();

        $this->getJson('/api/v1/events/no-such-event')->assertNotFound();
    }

    public function test_sale_state_follows_the_event(): void
    {
        $this->event->forceFill(['sales_status' => 'frozen'])->save();
        $this->getJson($this->base)->assertJsonPath('event.saleState', 'paused');

        $this->event->forceFill(['sales_status' => 'sold_out'])->save();
        $this->getJson($this->base)->assertJsonPath('event.saleState', 'sold_out');

        $this->event->forceFill(['sales_status' => 'open', 'made_bookable_at' => null])->save();
        $this->getJson($this->base)->assertJsonPath('event.saleState', 'countdown');
    }

    public function test_seat_map_shows_each_seats_state(): void
    {
        $a = $this->browser();
        $b = $this->browser();

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-1']])->assertOk();
        $this->event->seats()->where('seat_key', 'ORCH-B-3')->update(['status' => 'blocked']);

        $map = $this->asBuyer($b)->getJson($this->base.'/seats')->assertOk()->assertCookieMissing(CartToken::COOKIE);
        $seats = collect($map->json('seats'))->keyBy('key');

        $this->assertCount(170, $seats);
        $this->assertIsInt($map->json('serverTimeMs'));
        $this->assertSame('unavailable', $seats['ORCH-A-1']['state']); // sold in the demo order
        $this->assertSame('unavailable', $seats['ORCH-B-3']['state']); // blocked looks the same: the reason is private
        $this->assertSame('held', $seats['ORCH-B-1']['state']);
        $this->assertIsInt($seats['ORCH-B-1']['heldUntilMs']);
        $this->assertSame('available', $seats['ORCH-B-2']['state']);
        $this->assertSame(5000, $seats['ORCH-B-2']['priceCents']);

        $mine = collect($this->asBuyer($a)->getJson($this->base.'/seats')->json('seats'))->keyBy('key');
        $this->assertSame('mine', $mine['ORCH-B-1']['state']);
    }

    // ---------------------------------------------------------------- cart

    public function test_first_selection_issues_a_private_cookie(): void
    {
        $response = $this->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-1']])
            ->assertOk()
            ->assertJsonPath('state', 'selecting')
            ->assertJsonPath('seats.0', 'ORCH-B-1');

        $cookie = $response->getCookie(CartToken::COOKIE, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $cookie->getValue());

        // The token never appears in the body.
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());

        // Reading the cart without a cookie never mints one.
        $this->getJson($this->base.'/cart')->assertOk()->assertJsonPath('state', 'none')->assertCookieMissing(CartToken::COOKIE);
    }

        public function test_conflicts_return_409_and_change_nothing(): void
    {
        $a = $this->browser();
        $b = $this->browser();

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-2']])->assertOk();

        $this->asBuyer($b)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-2', 'ORCH-B-3']])
            ->assertStatus(409)
            ->assertJsonPath('error', 'seats_unavailable')
            ->assertJsonPath('problems.0', 'ORCH-B-2');

        $this->asBuyer($b)->getJson($this->base.'/cart')->assertJsonPath('state', 'none'); // B-3 was not taken

        // Guards the test setup itself: A's cart is still there under A's own cookie.
        $this->asBuyer($a)->getJson($this->base.'/cart')->assertJsonPath('seats', ['ORCH-B-2']);
    }
    public function test_bad_requests_get_a_consistent_error_shape(): void
    {
        $this->postJson($this->base.'/cart/seats', ['seats' => []])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->postJson($this->base.'/cart/seats', ['seats' => ['NOPE-1']])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_seats');

        // A form post is refused outright: only JSON may change data.
        $this->post($this->base.'/cart/seats', ['seats' => ['ORCH-B-1']])
            ->assertStatus(415)->assertJsonPath('error', 'json_required');
    }

    public function test_removing_seats_and_clearing_the_cart(): void
    {
        $a = $this->browser();
        $b = $this->browser();

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-4', 'ORCH-B-5']])->assertOk();

        $this->asBuyer($a)->postJson($this->base.'/cart/seats/remove', ['seats' => ['ORCH-B-4']])
            ->assertOk()->assertJsonPath('seats', ['ORCH-B-5']);

        $this->asBuyer($a)->deleteJson($this->base.'/cart')->assertOk()->assertJsonPath('state', 'none');

        $this->asBuyer($b)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-B-5']])->assertOk(); // free again
    }

    // ---------------------------------------------------------------- checkout

    public function test_checkout_from_cart_to_paid_order(): void
    {
        $a = $this->browser();
        $b = $this->browser();
        $seats = ['ORCH-C-1', 'BALC-B-1'];

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => $seats])->assertOk();

        $start = $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody($seats, ['coupon' => 'save10']))
            ->assertOk()
            ->assertJsonPath('order.status', 'pending_payment')
            ->assertJsonPath('order.subtotalCents', 8000)
            ->assertJsonPath('order.discountCents', 2000) // CAD 10 off EACH ticket
            ->assertJsonPath('order.totalCents', 6000)
            ->assertJsonPath('payment.status', 'pending');

        $reference = $start->json('order.reference');
        $this->assertMatchesRegularExpression('/^BK-[A-Z0-9]{8}$/', $reference);

        $this->asBuyer($a)->getJson($this->base.'/cart')->assertJsonPath('state', 'paying');

        $this->asBuyer($a)->getJson('/api/v1/orders/'.$reference)
            ->assertOk()->assertJsonPath('order.status', 'pending_payment')->assertJsonMissingPath('order.tickets');

        // The payment provider reports success (this stands in for the webhook).
        $payment = Order::where('reference', $reference)->firstOrFail()->payments()->firstOrFail();
        app(CheckoutService::class)->handlePaymentSucceeded($payment->provider_reference);

        $paid = $this->asBuyer($a)->getJson('/api/v1/orders/'.$reference)
            ->assertOk()->assertJsonPath('order.status', 'paid')->assertJsonCount(2, 'order.tickets');

        $tickets = collect($paid->json('order.tickets'))->keyBy('seat');
        $this->assertTrue($tickets['ORCH-C-1']['isYours']);
        $this->assertFalse($tickets['BALC-B-1']['isYours']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $tickets['ORCH-C-1']['token']);

        // Seeing the paid order released the buyer's cart, and the seats now show as taken.
        $this->asBuyer($a)->getJson($this->base.'/cart')->assertJsonPath('state', 'none');
        $map = collect($this->asBuyer($b)->getJson($this->base.'/seats')->json('seats'))->keyBy('key');
        $this->assertSame('unavailable', $map['ORCH-C-1']['state']);
    }

    public function test_pressing_pay_twice_returns_the_same_order(): void
    {
        $a = $this->browser();
        $seats = ['ORCH-C-2'];
        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => $seats])->assertOk();

        $first = $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody($seats))->assertOk();
        $second = $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody($seats))->assertOk();

        $this->assertSame($first->json('order.reference'), $second->json('order.reference'));
        $this->assertSame(1, Order::where('status', 'pending_payment')->count());
    }

    public function test_checkout_rejects_bad_details_without_locking_the_cart(): void
    {
        $a = $this->browser();
        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-C-3']])->assertOk();

        $this->asBuyer($a)->postJson($this->base.'/checkout', [
            'buyer_email' => 'b@example.test',
            'own_seat' => 'ORCH-C-3',
            'attendees' => ['ORCH-C-3' => ['name' => '']],
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->asBuyer($a)->postJson($this->base.'/checkout', [
            'buyer_email' => 'b@example.test',
            'own_seat' => 'ORCH-C-9',
            'attendees' => $this->people(['ORCH-C-3']),
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_attendees')->assertJsonPath('problems.0', 'own_seat');

        $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody(['ORCH-C-3'], ['coupon' => 'nosuchcode']))
            ->assertStatus(422)->assertJsonPath('error', 'coupon_invalid');

        $this->asBuyer($a)->getJson($this->base.'/cart')->assertJsonPath('state', 'selecting');
    }

    public function test_checkout_without_a_cart_is_refused(): void
    {
        $this->postJson($this->base.'/checkout', $this->checkoutBody(['ORCH-C-5']))
            ->assertStatus(409)->assertJsonPath('error', 'expired');
    }

    public function test_abort_cancels_the_order_and_unlocks_the_cart(): void
    {
        $a = $this->browser();
        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-C-3']])->assertOk();

        $reference = $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody(['ORCH-C-3']))
            ->assertOk()->json('order.reference');

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-C-4']])
            ->assertStatus(409)->assertJsonPath('error', 'cart_locked');

        $this->asBuyer($a)->postJson($this->base.'/checkout/abort', [])->assertOk()->assertJsonPath('state', 'selecting');
        $this->assertSame('cancelled', Order::where('reference', $reference)->value('status'));

        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-C-4']])->assertOk(); // editable again
    }

    public function test_order_status_is_private_to_the_buyer(): void
    {
        $a = $this->browser();
        $b = $this->browser();
        $this->asBuyer($a)->postJson($this->base.'/cart/seats', ['seats' => ['ORCH-C-6']])->assertOk();

        $reference = $this->asBuyer($a)->postJson($this->base.'/checkout', $this->checkoutBody(['ORCH-C-6']))
            ->assertOk()->json('order.reference');

        $this->asBuyer($b)->getJson('/api/v1/orders/'.$reference)->assertNotFound();
        $this->asBuyer('not-a-token')->getJson('/api/v1/orders/'.$reference)->assertNotFound(); // same as no cookie
        $this->asBuyer($b)->getJson('/api/v1/orders/BK-ZZZZZZZZ')->assertNotFound();           // same as a real one
        $this->asBuyer($a)->getJson('/api/v1/orders/'.$reference)->assertOk();
    }

    // ---------------------------------------------------------------- safety

    public function test_dev_payment_routes_do_not_exist_outside_local(): void
    {
        $this->postJson('/api/v1/dev/orders/BK-AAAAAAAA/pay', [])->assertNotFound();
        $this->postJson('/api/v1/dev/orders/BK-AAAAAAAA/fail', [])->assertNotFound();
    }

    public function test_checkout_is_rate_limited_per_cart(): void
    {
        $a = $this->browser();

        for ($i = 0; $i < 10; $i++) {
            $this->asBuyer($a)->postJson($this->base.'/checkout', [])->assertStatus(422);
        }

        $this->asBuyer($a)->postJson($this->base.'/checkout', [])->assertStatus(429);
    }

    public function test_new_carts_are_capped_per_address(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->base.'/cart/seats', ['seats' => []])->assertStatus(422); // each mints a token
        }

        $this->postJson($this->base.'/cart/seats', ['seats' => []])
            ->assertStatus(429)->assertJsonPath('error', 'too_many_carts');
    }
}