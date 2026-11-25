<?php

namespace Tests\Feature\Api;

use App\Events\SeatsChanged;
use App\Models\Event;
use App\Services\CheckoutService;
use App\Services\SeatHoldService;
use Database\Seeders\DemoEventSeeder;
use Database\Seeders\DemoFloorPlanSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class LiveSeatsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private SeatHoldService $holds;
    private CheckoutService $checkout;

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
        $this->holds = app(SeatHoldService::class);
        $this->checkout = app(CheckoutService::class);
    }

    // ---------------------------------------------------------------- helpers

    /** Every seat change announced so far, oldest first. */
    private function announced(): array
    {
        return EventFacade::dispatched(SeatsChanged::class)
            ->flatMap(fn ($call) => $call[0]->changes)
            ->values()
            ->all();
    }

    private function messages(): int
    {
        return EventFacade::dispatched(SeatsChanged::class)->count();
    }

    private function lastMessage(): SeatsChanged
    {
        return EventFacade::dispatched(SeatsChanged::class)->last()[0];
    }

    private function lastState(string $seatKey): ?string
    {
        $state = null;

        foreach ($this->announced() as $change) {
            if ($change['key'] === $seatKey) {
                $state = $change['state'];
            }
        }

        return $state;
    }

    // ---------------------------------------------------------------- tests

    public function test_taking_seats_announces_only_the_new_ones(): void
    {
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();

        $this->assertTrue($this->holds->select($this->event, $a, ['ORCH-B-1', 'ORCH-B-2'])->ok);

        $this->assertSame(1, $this->messages()); // one message for the whole selection
        $changes = $this->announced();
        $this->assertSame(['ORCH-B-1', 'ORCH-B-2'], collect($changes)->pluck('key')->sort()->values()->all());
        foreach ($changes as $change) {
            $this->assertSame('held', $change['state']);
            $this->assertIsInt($change['heldUntilMs']);
        }

        $this->holds->select($this->event, $a, ['ORCH-B-3']);
        $this->assertSame(2, $this->messages());
        $this->assertSame(['ORCH-B-3'], array_column($this->lastMessage()->changes, 'key'));

        // Picking a seat that is already in the cart is not news.
        $this->holds->select($this->event, $a, ['ORCH-B-3']);
        $this->assertSame(2, $this->messages());
    }

    public function test_failed_selections_announce_nothing(): void
    {
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();
        $b = $this->holds->newToken();

        $this->assertTrue($this->holds->select($this->event, $b, ['ORCH-B-2'])->ok);
        $this->assertSame(1, $this->messages());

        $conflict = $this->holds->select($this->event, $a, ['ORCH-B-2', 'ORCH-B-3']);
        $this->assertSame('seats_unavailable', $conflict->reason);

        $sold = $this->holds->select($this->event, $a, ['ORCH-A-1']); // sold in the demo order
        $this->assertSame('seats_not_sellable', $sold->reason);

        $this->assertSame(1, $this->messages());
    }

    public function test_releasing_seats_announces_them_as_available(): void
    {
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();

        $this->holds->select($this->event, $a, ['ORCH-B-1', 'ORCH-B-2']);

        $this->holds->deselect($this->event, $a, ['ORCH-B-1']);
        $this->assertSame('available', $this->lastState('ORCH-B-1'));
        $this->assertSame('held', $this->lastState('ORCH-B-2'));

        $this->holds->release($this->event, $a);
        $this->assertSame('available', $this->lastState('ORCH-B-2'));
    }

    public function test_a_sale_is_announced_and_releasing_the_cart_afterwards_does_not_undo_it(): void
    {
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();

        $this->assertTrue($this->holds->select($this->event, $a, ['ORCH-C-1'])->ok);

        $start = $this->checkout->start(
            $this->event,
            $a,
            'buyer@example.test',
            ['ORCH-C-1' => ['name' => 'Person 1', 'email' => '']],
            'ORCH-C-1',
        );
        $this->assertTrue($start->ok);

        $this->assertTrue($this->checkout->handlePaymentSucceeded($start->payment->provider_reference)->ok);
        $this->assertSame('unavailable', $this->lastState('ORCH-C-1'));

        // This is what the order page does once the order is paid.
        $this->holds->release($this->event, $a);

        $this->assertSame('unavailable', $this->lastState('ORCH-C-1'));
    }

    public function test_pressing_pay_announces_the_extended_hold(): void
    {
        // A 6 second window, so the 5 minute payment grace is longer and extends it.
        config(['bookcat.holds.min_minutes' => 0, 'bookcat.holds.minutes' => 0.1]);
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();

        $this->assertTrue($this->holds->select($this->event, $a, ['ORCH-B-1'])->ok);
        $first = $this->announced()[0]['heldUntilMs'];

        $this->assertTrue($this->holds->beginPayment($this->event, $a)->ok);

        $all = $this->announced();
        $last = $all[array_key_last($all)];

        $this->assertSame('held', $last['state']);
        $this->assertGreaterThan($first + 240000, $last['heldUntilMs']);
    }

    public function test_a_broadcast_failure_never_breaks_a_selection(): void
    {
        EventFacade::listen(SeatsChanged::class, function () {
            throw new RuntimeException('Reverb is down');
        });

        $a = $this->holds->newToken();
        $result = $this->holds->select($this->event, $a, ['ORCH-B-1']);

        $this->assertTrue($result->ok);
        $this->assertSame(['ORCH-B-1'], $this->holds->status($this->event, $a)->seats);
    }

    public function test_messages_carry_only_public_seat_facts_on_a_public_channel(): void
    {
        EventFacade::fake([SeatsChanged::class]);
        $a = $this->holds->newToken();

        $this->holds->select($this->event, $a, ['ORCH-B-1']);
        $message = $this->lastMessage();

        $this->assertSame($this->event->slug, $message->eventSlug);
        $this->assertSame(['event.'.$this->event->slug], array_map(fn ($channel) => $channel->name, $message->broadcastOn()));
        $this->assertSame('seats.changed', $message->broadcastAs());
        $this->assertSame(['at', 'changes'], array_keys($message->broadcastWith()));
        $this->assertSame(['key', 'state', 'heldUntilMs'], array_keys($message->changes[0]));
        $this->assertStringNotContainsString($a, json_encode($message->broadcastWith())); // never the cart token
    }
}