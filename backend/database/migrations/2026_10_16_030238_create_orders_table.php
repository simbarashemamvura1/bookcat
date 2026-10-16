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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();     // public order number, e.g. BK-7F3K9Q2M
            $table->string('type')->default('purchase');    // purchase | comp (organizer-issued)

            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('attendee_account_id')->nullable()->constrained('attendee_accounts')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('business_accounts')->nullOnDelete(); // comp orders

            // Always stored, even for members, so guest orders can be linked later by verified email.
            $table->string('buyer_name');
            $table->string('buyer_email')->index();

            $table->string('currency', 3);
            $table->unsignedInteger('subtotal_cents')->default(0);  // sum of ticket list prices
            $table->unsignedInteger('discount_cents')->default(0);  // sum of per-ticket coupon discounts
            $table->unsignedInteger('total_cents')->default(0);     // what the buyer pays (fees are absorbed by the organizer)
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();

            $table->string('status')->default('pending_payment'); // pending_payment | paid | cancelled | partially_refunded | refunded
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};