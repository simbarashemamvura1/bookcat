<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $keys = [
            'manage_platform_settings' => 'Manage platform settings',
            'create_organizer' => 'Create organizer',
            'approve_organizer' => 'Approve organizer',
            'create_preapproved_organizer' => 'Create pre-approved organizer',
            'suspend_organizer' => 'Suspend organizer (flag/pause)',
            'create_event_directly' => 'Create event directly',
            'approve_event' => 'Approve event',
            'activate_event' => 'Activate event',
            'mark_floor_plan_ready' => 'Mark floor plan ready',
            'upload_blueprint_floor_map' => 'Upload blueprint floor map',
            'create_venue' => 'Create a new venue',
            'review_venue_request' => 'Review organizer venue requests',
            'freeze_event_booking' => 'Freeze event booking',
            'reactivate_event' => 'Reactivate frozen event',
            'override_financial_terms' => 'Override fees / reserve / payout terms',
            'record_manual_payout' => 'Record manual payout',
            'book_ticket_admin_override' => 'Book a ticket with admin override',
            'create_coupon' => 'Create coupons',
        ];

        foreach ($keys as $key => $label) {
            Permission::updateOrCreate(['key' => $key], ['label' => $label]);
        }
    }
}