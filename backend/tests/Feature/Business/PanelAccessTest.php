<?php

namespace Tests\Feature\Business;

use App\Models\BusinessAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PanelAccessTest extends TestCase
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

    private function account(string $role, string $status = 'active'): BusinessAccount
    {
        $account = new BusinessAccount([
            'name' => ucfirst($role),
            'email' => $role.'-'.$status.'@bookcat.test',
            'password' => 'password',
        ]);

        $account->forceFill(['role' => $role, 'status' => $status])->save();

        return $account;
    }

    public function test_active_admins_and_super_admins_can_open_the_panel(): void
    {
        $panel = Filament::getPanel('business');

        $this->assertTrue($this->account(BusinessAccount::ROLE_ADMIN)->canAccessPanel($panel));
        $this->assertTrue($this->account(BusinessAccount::ROLE_SUPER_ADMIN)->canAccessPanel($panel));
    }

    public function test_organizers_and_inactive_staff_are_refused(): void
    {
        $panel = Filament::getPanel('business');

        $this->assertFalse($this->account(BusinessAccount::ROLE_ORGANIZER)->canAccessPanel($panel));
        $this->assertFalse($this->account(BusinessAccount::ROLE_ADMIN, 'suspended')->canAccessPanel($panel));
        $this->assertFalse($this->account(BusinessAccount::ROLE_SUPER_ADMIN, 'pending')->canAccessPanel($panel));
    }

    public function test_the_login_page_is_reachable(): void
    {
        $this->get('/business/login')->assertOk();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/business')->assertRedirectContains('/business/login');
    }
}