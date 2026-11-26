<?php

namespace Tests\Feature\Business;

use App\Models\BusinessAccount;
use App\Models\Organizer;
use App\Models\Permission;
use App\Services\OrganizerAdmin;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrganizerAdminTest extends TestCase
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

    private function data(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Demo Co',
            'contact_phone' => '519-555-0100',
            'bio' => 'We run shows.',
            'origin' => 'sales_assisted',
            'account_name' => 'Pat Organizer',
            'account_email' => 'Pat-'.Str::random(6).'@Example.test',
            'account_password' => 'a-long-temporary-password',
        ], $overrides);
    }

    private function pending(array $overrides = []): Organizer
    {
        return app(OrganizerAdmin::class)->create($this->superAdmin(), $this->data($overrides));
    }

    // ---------------------------------------------------------------- tests

    public function test_creating_an_organizer_makes_a_pending_organizer_and_account(): void
    {
        $organizer = $this->pending(['account_email' => 'Pat@Example.test', 'account_password' => 'a-long-temporary-password']);

        $this->assertSame(Organizer::STATUS_PENDING, $organizer->application_status);
        $this->assertSame('sales_assisted', $organizer->origin);
        $this->assertNull($organizer->slug);

        $account = $organizer->account;
        $this->assertSame('pat@example.test', $account->email);          // stored lowercase
        $this->assertSame(BusinessAccount::ROLE_ORGANIZER, $account->role);
        $this->assertSame('pending', $account->status);
        $this->assertTrue(Hash::check('a-long-temporary-password', $account->password));
    }

    public function test_pre_approved_creation_needs_its_own_permission(): void
    {
        $limited = $this->admin(['create_organizer']);
        $threw = false;

        try {
            app(OrganizerAdmin::class)->create($limited, $this->data(['origin' => 'pre_approved']));
        } catch (AuthorizationException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(0, BusinessAccount::where('role', BusinessAccount::ROLE_ORGANIZER)->count()); // nothing half-created

        $allowed = $this->admin(['create_organizer', 'create_preapproved_organizer']);
        $organizer = app(OrganizerAdmin::class)->create($allowed, $this->data(['origin' => 'pre_approved']));

        $this->assertSame(Organizer::STATUS_APPROVED, $organizer->application_status);
        $this->assertSame('active', $organizer->account->status);
        $this->assertNotNull($organizer->slug);
    }

    public function test_approving_activates_the_account_and_assigns_a_slug(): void
    {
        $organizer = $this->pending(['business_name' => 'Big Shows Inc.']);
        $approver = $this->admin(['approve_organizer']);

        app(OrganizerAdmin::class)->approve($organizer, $approver);

        $organizer->refresh();
        $this->assertSame(Organizer::STATUS_APPROVED, $organizer->application_status);
        $this->assertSame('big-shows-inc', $organizer->slug);
        $this->assertSame($approver->id, $organizer->reviewed_by);
        $this->assertSame('active', $organizer->account->status);
    }

    public function test_approval_is_refused_without_the_permission(): void
    {
        $organizer = $this->pending();
        $notAllowed = $this->admin(['create_organizer']);
        $threw = false;

        try {
            app(OrganizerAdmin::class)->approve($organizer, $notAllowed);
        } catch (AuthorizationException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(Organizer::STATUS_PENDING, $organizer->refresh()->application_status);
        $this->assertSame('pending', $organizer->account->status);
    }

    public function test_changes_can_be_requested_and_the_organizer_approved_afterwards(): void
    {
        $organizer = $this->pending();
        $reviewer = $this->admin(['approve_organizer']);

        app(OrganizerAdmin::class)->requestChanges($organizer, $reviewer, 'Please add your business registration.');

        $organizer->refresh();
        $this->assertSame(Organizer::STATUS_CHANGES_REQUESTED, $organizer->application_status);
        $this->assertSame('Please add your business registration.', $organizer->review_notes);

        app(OrganizerAdmin::class)->approve($organizer, $reviewer);

        $organizer->refresh();
        $this->assertSame(Organizer::STATUS_APPROVED, $organizer->application_status);
        $this->assertNull($organizer->review_notes);
    }

    public function test_rejecting_closes_the_account_and_cannot_be_undone_by_approving(): void
    {
        $organizer = $this->pending();
        $reviewer = $this->admin(['approve_organizer']);

        app(OrganizerAdmin::class)->reject($organizer, $reviewer, 'Not a real business.');

        $organizer->refresh();
        $this->assertSame(Organizer::STATUS_REJECTED, $organizer->application_status);
        $this->assertSame('rejected', $organizer->account->status);

        $this->expectException(DomainException::class);
        app(OrganizerAdmin::class)->approve($organizer, $reviewer);
    }

    public function test_only_approved_organizers_can_be_flagged_and_unflagged(): void
    {
        $organizer = $this->pending();
        $service = app(OrganizerAdmin::class);
        $flagger = $this->admin(['approve_organizer', 'suspend_organizer']);
        $threw = false;

        try {
            $service->flag($organizer, $flagger, 'Chargeback complaints.');
        } catch (DomainException) {
            $threw = true;
        }

        $this->assertTrue($threw); // still pending, so it cannot be flagged

        $service->approve($organizer, $flagger);
        $service->flag($organizer, $flagger, 'Chargeback complaints.');

        $organizer->refresh();
        $this->assertNotNull($organizer->flagged_at);
        $this->assertSame('Chargeback complaints.', $organizer->flag_reason);

        $service->unflag($organizer, $flagger);

        $organizer->refresh();
        $this->assertNull($organizer->flagged_at);
        $this->assertNull($organizer->flag_reason);
    }

    public function test_organizers_with_the_same_business_name_get_distinct_slugs(): void
    {
        $approver = $this->admin(['approve_organizer']);
        $service = app(OrganizerAdmin::class);

        $first = $this->pending(['business_name' => 'Demo Co']);
        $second = $this->pending(['business_name' => 'Demo Co']);

        $service->approve($first, $approver);
        $service->approve($second, $approver);

        $this->assertSame('demo-co', $first->refresh()->slug);
        $this->assertSame('demo-co-2', $second->refresh()->slug);
    }
}