<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // Ownership: every organizer action must check organizer_id (roles matrix 0b)
            $table->foreignId('organizer_id')->constrained('organizers');
            $table->foreignId('created_by')->constrained('business_accounts'); // organizer, or admin creating directly

            // Venue is derived from the floor plan, so the two can never disagree.
            $table->foreignId('floor_plan_id')->nullable()->constrained('floor_plans');

            // Nullable because a draft is filled in step by step in the wizard.
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index(); // becomes a FK when category management (#47) is built
            $table->text('restrictions')->nullable();
            $table->text('refund_policy')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('currency', 3);                   // CAD | USD
            $table->boolean('is_free')->default(false);      // declared "fully free" event: no fees at all

            // Gate 1: Approve (content/compliance)
            $table->string('review_status')->default('draft')->index(); // draft | submitted | approved | rejected
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Create-event fee gate (the payment record itself comes with the payments tables)
            $table->timestamp('creation_fee_paid_at')->nullable();

            // Gate 2: Activate (admin master switch, financial gate)
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('business_accounts')->nullOnDelete();

            // Organizer toggles: independent of each other
            $table->timestamp('made_public_at')->nullable();
            $table->timestamp('made_bookable_at')->nullable();

            // Sales control once bookable: freeze (temporary) or sold out (final)
            $table->string('sales_status')->default('open'); // open | frozen | sold_out
            $table->foreignId('frozen_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->string('frozen_by_type')->nullable();    // organizer | admin: decides who may reactivate
            $table->timestamp('frozen_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};