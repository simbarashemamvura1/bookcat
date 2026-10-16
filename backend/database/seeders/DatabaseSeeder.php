<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        // Local development only. Never seed known passwords or demo data in production.
        if (app()->environment('local')) {
            $this->call(SuperAdminSeeder::class);
            $this->call(DemoFloorPlanSeeder::class);
            $this->call(DemoEventSeeder::class);
        }
    }
}