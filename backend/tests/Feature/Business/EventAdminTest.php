<?php

namespace Tests\Feature\Business;

use App\Models\BusinessAccount;
use App\Models\Event;
use App\Models\FloorPlan;
use App\Models\FloorPlanSeat;
use App\Models\Organizer;
use App\Models\Permission;
use App\Models\Venue;
use App\Services\EventAdmin;
use App\Services\OrganizerAdmin;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EventAdminTest extends TestCase
{
    use RefreshDatabase;

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

        $this->seed(PermissionSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    private function account(string $role): BusinessAccount
    {
        $account = new BusinessAccount([
            'name' => ucfirst($role),
            'email' => $role.'-'.Str::random(8).'@bookcat.test',
            'password' => 'password',
        ]);
        $account->forceFill(['role' => $role, 'status' => 'active'])->save();

        return $account;
    }

    private function superAdmin(): BusinessAccount
    {
        return BusinessAccount::where('role', BusinessAccount::ROLE_SUPER_ADMIN)->first()
            ?? $this->account(BusinessAccount::ROLE_SUPER_ADMIN);
    }

    /** An admin holding exactly these permissions. */
    private function admin(array $keys): BusinessAccount
    {
        $super = $this->superAdmin();
        $admin = $this->account(BusinessAccount::ROLE_ADMIN);

        foreach (Permission::whereIn('key', $keys)->get() as $permission) {
            $admin->permissions()->attach($permission->id, ['granted_by' => $super->id, 'granted_at' => now()]);
        }

        return $admin;
    }

    private function organizer(string $origin = 'pre_approved'): Organizer
    {
        return app(OrganizerAdmin::class)->create($this->superAdmin(), [
            'business_name' => 'Demo Co',
            'origin' => $origin,
            'account_name' => 'Pat Organizer',
            'account_email' => 'pat-'.Str::random(6).'@example.test',
            'account_password' => 'a-long-temporary-password',
        ]);
    }

    /** A ready floor plan with 3 ORCH seats and 2 BALC seats. */
    private function plan(?Organizer $owner = null, string $status = FloorPlan::STATUS_READY): FloorPlan
    {
        $super = $this->superAdmin();

        $venue = new Venue([
            'name' => 'Test Hall', 'address_line' => '1 Main St', 'city' => 'Windsor',
            'region' => 'ON', 'country' => 'CA', 'postal_code' => 'N9A 1A1', 'timezone' => 'America/Toronto',
        ]);
        $venue->forceFill(['created_by' => $super->id, 'status' => Venue::STATUS_APPROVED])->save();

        $plan = new FloorPlan(['name' => 'Main']);
        $plan->forceFill([
            'venue_id' => $venue->id,
            'owner_organizer_id' => $owner?->id,
            'kind' => $owner ? FloorPlan::KIND_CUSTOM : FloorPlan::KIND_TEMPLATE,
            'status' => $status,
            'ready_by' => $status === FloorPlan::STATUS_READY ? $super->id : null,
            'ready_at' => $status === FloorPlan::STATUS_READY ? now() : null,
        ])->save();

        foreach (['ORCH' => 3, 'BALC' => 2] as $section => $count) {
            for ($i = 1; $i <= $count; $i++) {
                FloorPlanSeat::create([
                    'floor_plan_id' => $plan->id,
                    'seat_key' => FloorPlanSeat::makeKey($section, 'A', $i),
                    'section' => $section,
                    'row_label' => 'A',
                    'seat_number' => (string) $i,
                ]);
            }
        }

        return $plan;
    }

    private function eventData(Organizer $organizer, FloorPlan $plan, array $overrides = []): array
    {
        return array_merge([
            'organizer_id' => $organizer->id,
            'floor_plan_id' => $plan->id,
            'title' => 'Test Show',
            'description' => 'A show.',
            'category' => 'Entertaining',
            'starts_at' => now()->addMonths(2),
            'currency' => 'CAD',
            'is_free' => false,
        ], $overrides);
    }

    private function drafted(array $overrides = []): Event
    {
        return app(EventAdmin::class)->create(
            $this->superAdmin(),
            $this->eventData($this->organizer(), $this->plan(), $overrides),
        );
    }

    private function priced(Event $event): Event
    {
        app(EventAdmin::class)->setSectionPrices($event, $this->superAdmin(), [
            ['section' => 'ORCH', 'cents' => 5000],
            ['section' => 'BALC', 'cents' => 3000],
        ]);

        return $event;
    }

    /** Walks a fresh event through every gate, as a super admin. */
    private function onSale(): Event
    {
        $service = app(EventAdmin::class);
        $super = $this->superAdmin();
        $event = $this->priced($this->drafted());

        $service->approve($event, $super);
        $service->markCreationFeePaid($event, $super);
        $service->activate($event, $super);
        $service->makePublic($event, $super);
        $service->makeBookable($event, $super);

        return $event->refresh();
    }

    private function assertRefused(callable $fn, string $exception = DomainException::class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            return;
        }

        $this->fail('Expected '.$exception.' but nothing was thrown.');
    }

    // ---------------------------------------------------------------- tests

    public function test_creating_an_event_copies_the_seats_and_fee_terms(): void
    {
        $event = $this->drafted();

        $this->assertSame(Event::REVIEW_DRAFT, $event->review_status);
        $this->assertSame($this->superAdmin()->id, $event->created_by);
        $this->assertNotEmpty($event->slug);
        $this->assertSame(5, $event->seats()->count());
        $this->assertSame(5, $event->seats()->whereNull('price_cents')->count());
        $this->assertEquals(config('bookcat.fees.CAD.ticket_fee_bps'), $event->financialTerms->ticket_fee_bps);
    }

    public function test_creating_needs_the_permission_and_leaves_nothing_behind(): void
    {
        $organizer = $this->organizer();
        $plan = $this->plan();
        $limited = $this->admin(['approve_event']);

        $this->assertRefused(
            fn () => app(EventAdmin::class)->create($limited, $this->eventData($organizer, $plan)),
            AuthorizationException::class,
        );

        $this->assertSame(0, Event::count());
    }

    public function test_events_cannot_be_created_for_unapproved_organizers_or_foreign_floor_plans(): void
    {
        $service = app(EventAdmin::class);
        $super = $this->superAdmin();

        $pending = $this->organizer('sales_assisted');
        $this->assertRefused(fn () => $service->create($super, $this->eventData($pending, $this->plan())));

        $organizer = $this->organizer();
        $someoneElses = $this->plan($this->organizer());
        $this->assertRefused(fn () => $service->create($super, $this->eventData($organizer, $someoneElses)));

        $notReady = $this->plan($organizer, FloorPlan::STATUS_IN_PROGRESS);
        $this->assertRefused(fn () => $service->create($super, $this->eventData($organizer, $notReady)));

        $this->assertSame(0, Event::count());
    }

    public function test_usd_is_refused_until_a_fee_schedule_exists(): void
    {
        config(['bookcat.fees.USD' => null]);

        $organizer = $this->organizer();
        $plan = $this->plan();

        $this->assertRefused(fn () => app(EventAdmin::class)->create(
            $this->superAdmin(),
            $this->eventData($organizer, $plan, ['currency' => 'USD']),
        ));

        $this->assertSame(0, Event::count());
    }

    public function test_approval_needs_every_seat_priced_and_then_locks_the_layout(): void
    {
        $service = app(EventAdmin::class);
        $super = $this->superAdmin();
        $event = $this->drafted();

        $this->assertRefused(fn () => $service->approve($event, $super)); // nothing priced yet

        $service->setSectionPrices($event, $super, [['section' => 'ORCH', 'cents' => 5000]]);
        $this->assertRefused(fn () => $service->approve($event, $super)); // BALC still unpriced

        $service->setSectionPrices($event, $super, [['section' => 'BALC', 'cents' => 3000]]);
        $service->approve($event, $super);

        $event->refresh();
        $this->assertSame(Event::REVIEW_APPROVED, $event->review_status);
        $this->assertSame(3, $event->seats()->where('section', 'ORCH')->where('price_cents', 5000)->count());

        $this->assertRefused(fn () => $service->setSectionPrices($event, $super, [['section' => 'ORCH', 'cents' => 9900]]));
        $this->assertRefused(fn () => $service->setSectionPrices($this->drafted(), $super, [['section' => 'NOPE', 'cents' => 100]]));
    }

    public function test_free_events_cannot_have_prices_above_zero(): void
    {
        $service = app(EventAdmin::class);
        $super = $this->superAdmin();
        $event = $this->drafted(['is_free' => true]);

        $this->assertRefused(fn () => $service->setSectionPrices($event, $super, [['section' => 'ORCH', 'cents' => 100]]));

        $service->setSectionPrices($event, $super, [['section' => 'ORCH', 'cents' => 0], ['section' => 'BALC', 'cents' => 0]]);
        $service->approve($event, $super);

        $this->assertSame(Event::REVIEW_APPROVED, $event->refresh()->review_status);
    }

    public function test_gates_open_in_order_and_the_event_goes_on_sale(): void
    {
        $service = app(EventAdmin::class);
        $super = $this->superAdmin();
        $event = $this->priced($this->drafted());

        $this->assertRefused(fn () => $service->makePublic($event, $super));   // not approved
        $service->approve($event, $super);

        $this->assertRefused(fn () => $service->makePublic($event, $super));   // fee not paid, not activated
        $service->markCreationFeePaid($event, $super);

        $this->assertRefused(fn () => $service->makePublic($event, $super));   // not activated
        $service->activate($event, $super);

        $this->assertRefused(fn () => $service->makeBookable($event, $super)); // Bookable requires Public
        $service->makePublic($event, $super);
        $service->makeBookable($event, $super);

        $event->refresh();
        $this->assertTrue($event->isVisibleToPublic());
        $this->assertTrue($event->isPurchasable());

        $this->assertRefused(fn () => $service->makePrivate($event, $super));  // still bookable
        $service->makeNotBookable($event, $super);
        $service->makePrivate($event, $super);

        $this->assertFalse($event->refresh()->isVisibleToPublic());
    }

    public function test_freeze_and_reactivate_follow_their_own_permissions(): void
    {
        $service = app(EventAdmin::class);
        $event = $this->onSale();
        $this->assertTrue($event->isPurchasable());

        $freezer = $this->admin(['freeze_event_booking']);
        $service->freeze($event, $freezer);

        $event->refresh();
        $this->assertSame(Event::SALES_FROZEN, $event->sales_status);
        $this->assertSame('admin', $event->frozen_by_type);
        $this->assertFalse($event->isPurchasable());

        $this->assertRefused(fn () => $service->reactivate($event, $freezer), AuthorizationException::class);

        $service->reactivate($event, $this->admin(['reactivate_event']));

        $event->refresh();
        $this->assertSame(Event::SALES_OPEN, $event->sales_status);
        $this->assertNull($event->frozen_by);
        $this->assertTrue($event->isPurchasable());
    }
}