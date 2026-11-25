<?php

namespace Database\Seeders;

use App\Models\BusinessAccount;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $account = BusinessAccount::firstOrNew(['email' => 'superadmin@bookcat.test']);

        $account->forceFill([
            'name' => 'Dev Super Admin',
            'password' => 'password',
            'role' => BusinessAccount::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ])->save();
    }
}