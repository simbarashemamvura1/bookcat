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

        // Local development only. Never seed a known password in production.
        if (app()->environment('local')) {
            $this->call(SuperAdminSeeder::class);
        }
    }
}