<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CartToken;
use App\Jobs\SendOrderTickets;
use App\Mail\TicketsEmail;
use App\Models\Event;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\SeatHoldService;
use App\Services\TicketMailer;
use Database\Seeders\DemoEventSeeder;
use Database\Seeders\DemoFloorPlanSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class TicketEmailsTest extends TestCase
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

    private function browser(): string
    {
        return app(SeatHoldService::class)->newToken();
    }

    private function asBuyer(string $token): static
    {
        return $this->withCredentials()->withUnencryptedCookie(CartToken::COOKIE, $token);
    }

    /** The first seat is the buyer's own (no separate email); every other seat gets its own address. */
    private function people(array $seats): array
    {
        $out = [];

        foreach (array_values($seats) as $i => $key) {
            $out[$key] = ['name' => 'Person '.($i + 1), 'email' => $i === 0 ? '' : 'p'.($i + 1).'@example.test'];
        }

        return $out;
    }

    /** Buys the seats through the real API. With $pay = false the order is left waiting for payment. */
    private function buy(array $seats, bool $pay = true): Order
    {
        $token = $this->browser();

        $this->asBuyer($token)->postJson($this->base.'/cart/seats', ['seats' => $seats])->assertOk();

        $reference = $this->asBuyer($token)->postJson($this->base.'/checkout', [
            'buyer_email' => 'Buyer@Example.test',
            'own_seat' => $seats[0],
            'attendees' => $this->people($seats),
        ])->assertOk()->json('order.reference');

        $order = Order::where('reference', $reference)->firstOrFail();

        if ($pay) {
            // The payment provider reports success (this stands in for the webhook).
            app(CheckoutService::class)->handlePaymentSucceeded($order->payments()->firstOrFail()->provider_reference);
        }

        return $order->refresh();
    }

    // ---------------------------------------------------------------- tests

    public function test_buyer_and_attendee_each_get_their_own_email(): void
    {
        Mail::fake();

        $order = $this->buy(['ORCH-C-1', 'BALC-B-1']);

        Mail::assertSent(TicketsEmail::class, 2);

        // The buyer gets every ticket on the order (the address is stored lowercase).
        Mail::assertSent(TicketsEmail::class, fn (TicketsEmail $mail) => $mail->hasTo('buyer@example.test')
            && $mail->forBuyer
            && $mail->tickets->count() === 2);

        // The additional attendee gets only their own.
        Mail::assertSent(TicketsEmail::class, fn (TicketsEmail $mail) => $mail->hasTo('p2@example.test')
            && ! $mail->forBuyer
            && $mail->tickets->count() === 1);

        $this->assertNotNull($order->tickets_emailed_at);
        $this->assertNotNull($order->tickets()->where('is_buyer_seat', false)->firstOrFail()->attendee_emailed_at);
    }

    public function test_running_the_job_again_sends_nothing_new(): void
    {
        Mail::fake();

        $order = $this->buy(['ORCH-C-1', 'BALC-B-1']);

        app(TicketMailer::class)->send($order->id);
        app(TicketMailer::class)->send($order->id);

        Mail::assertSent(TicketsEmail::class, 2); // still just the original two
    }

    public function test_the_real_email_renders_with_inline_qr_codes(): void
    {
        // No fake here: the whole email is built and handed to the (array) transport.
        $order = $this->buy(['ORCH-C-1', 'BALC-B-1']);

        $this->assertNotNull($order->tickets_emailed_at);

        $buyerMessages = Mail::getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->toString())
            ->filter(fn (string $raw) => str_contains($raw, $order->reference));

        $this->assertCount(1, $buyerMessages);

        $raw = $buyerMessages->first();
        $this->assertStringContainsString('image/png', $raw);   // the QR codes travel inside the email
        $this->assertStringContainsString('Content-ID', $raw);
    }

    public function test_unpaid_orders_get_no_tickets(): void
    {
        Mail::fake();

        $order = $this->buy(['ORCH-C-1', 'BALC-B-1'], pay: false);

        // Even if the job runs by mistake, a pending order sends nothing.
        app(TicketMailer::class)->send($order->id);

        Mail::assertNothingSent();
        $this->assertNull($order->refresh()->tickets_emailed_at);
    }

    public function test_the_sweep_requeues_a_missed_email_once(): void
    {
        Mail::fake();

        $order = $this->buy(['ORCH-C-1', 'BALC-B-1']);

        // Pretend the job was lost: paid a while ago, buyer email never recorded.
        Order::whereKey($order->id)->update(['tickets_emailed_at' => null, 'paid_at' => now()->subMinutes(5)]);

        Queue::fake();

        app(TicketMailer::class)->sweep();
        app(TicketMailer::class)->sweep(); // the second pass must not queue it again so soon

        $mine = Queue::pushed(SendOrderTickets::class, fn (SendOrderTickets $job) => $job->orderId === $order->id);
        $this->assertCount(1, $mine);
    }
}