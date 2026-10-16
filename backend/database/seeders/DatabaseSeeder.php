<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        // Local development only. Never seed known passwords or demo data in production.
        // Model events stay ON while seeding: the models rely on them (order reference,
        // ticket token, coupon rules), so seeded data must go through the same rules as real data.
        if (app()->environment('local')) {
            $this->call(SuperAdminSeeder::class);
            $this->call(DemoFloorPlanSeeder::class);
            $this->call(DemoEventSeeder::class);
            $this->call(DemoOrderSeeder::class);
        }
    }
}