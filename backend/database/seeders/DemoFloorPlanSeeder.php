<?php

namespace Database\Seeders;

use App\Models\BusinessAccount;
use App\Models\FloorPlan;
use App\Models\FloorPlanSeat;
use App\Models\Venue;
use Illuminate\Database\Seeder;

class DemoFloorPlanSeeder extends Seeder
{
    public function run(): void
    {
        if (Venue::where('name', 'Demo Theatre')->exists()) {
            return;
        }

        $admin = BusinessAccount::where('role', BusinessAccount::ROLE_SUPER_ADMIN)->firstOrFail();

        $venue = new Venue([
            'name' => 'Demo Theatre',
            'address_line' => '1 Example Street',
            'city' => 'Windsor',
            'region' => 'ON',
            'country' => 'CA',
            'timezone' => 'America/Toronto',
        ]);
        $venue->forceFill([
            'created_by' => $admin->id,
            'status' => Venue::STATUS_APPROVED,
        ])->save();

        $plan = new FloorPlan(['name' => 'Demo Theatre - Standard Layout']);
        $plan->forceFill([
            'venue_id' => $venue->id,
            'kind' => FloorPlan::KIND_TEMPLATE,
            'status' => FloorPlan::STATUS_READY,
            'ready_by' => $admin->id,
            'ready_at' => now(),
        ])->save();

        // ORCH: rows A-J x 12 seats = 120, BALC: rows A-E x 10 seats = 50 (170 total)
        $layout = [
            'ORCH' => ['rows' => range('A', 'J'), 'count' => 12],
            'BALC' => ['rows' => range('A', 'E'), 'count' => 10],
        ];

        $seats = [];
        foreach ($layout as $section => $config) {
            foreach ($config['rows'] as $row) {
                for ($n = 1; $n <= $config['count']; $n++) {
                    $seats[] = [
                        'floor_plan_id' => $plan->id,
                        'seat_key' => FloorPlanSeat::makeKey($section, $row, $n),
                        'section' => $section,
                        'row_label' => $row,
                        'seat_number' => (string) $n,
                    ];
                }
            }
        }

        FloorPlanSeat::insert($seats);
    }
}