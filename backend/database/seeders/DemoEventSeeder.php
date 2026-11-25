<?php

namespace Database\Seeders;

use App\Models\BusinessAccount;
use App\Models\Event;
use App\Models\EventFinancialTerms;
use App\Models\FloorPlan;
use App\Models\Organizer;
use Illuminate\Database\Seeder;

class DemoEventSeeder extends Seeder
{
    public function run(): void
    {
        if (Event::where('title', 'Demo Concert')->exists()) {
            return;
        }

        $super = BusinessAccount::where('role', BusinessAccount::ROLE_SUPER_ADMIN)->firstOrFail();
        $plan = FloorPlan::where('kind', FloorPlan::KIND_TEMPLATE)
            ->where('status', FloorPlan::STATUS_READY)
            ->firstOrFail();

        $account = new BusinessAccount([
            'name' => 'Demo Organizer',
            'email' => 'organizer@bookcat.test',
            'password' => 'password',
        ]);
        $account->forceFill(['role' => BusinessAccount::ROLE_ORGANIZER, 'status' => 'active'])->save();

        $organizer = $account->organizer()->make(['business_name' => 'Demo Events Co.']);
        $organizer->forceFill([
            'origin' => 'sales_assisted',
            'application_status' => Organizer::STATUS_APPROVED,
            'reviewed_by' => $super->id,
            'reviewed_at' => now(),
        ])->save();

        $event = new Event([
            'title' => 'Demo Concert',
            'description' => 'Seeded demo event for local development.',
            'category' => 'Entertaining',
            'starts_at' => now()->addMonths(2),
            'currency' => 'CAD',
            'is_free' => false,
        ]);
        $event->forceFill([
            'organizer_id' => $organizer->id,
            'created_by' => $account->id,
            'floor_plan_id' => $plan->id,
            'slug' => Event::generateSlug('Demo Concert'),
        ])->save();

        EventFinancialTerms::createDefaultsFor($event);

        // Snapshot and price while the event is still a draft
        $event->snapshotSeats();
        $event->seats()->where('section', 'ORCH')->update(['price_cents' => 5000]);
        $event->seats()->where('section', 'BALC')->update(['price_cents' => 3000]);

        // Walk it through every gate
        $event->forceFill([
            'review_status' => Event::REVIEW_APPROVED,
            'reviewed_by' => $super->id,
            'reviewed_at' => now(),
            'creation_fee_paid_at' => now(),
            'activated_at' => now(),
            'activated_by' => $super->id,
            'made_public_at' => now(),
            'made_bookable_at' => now(),
        ])->save();
    }
}