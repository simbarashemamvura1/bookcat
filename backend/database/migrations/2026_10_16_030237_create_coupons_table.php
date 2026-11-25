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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            // A coupon belongs to one event, so ownership is event.organizer_id (roles matrix 0b).
            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('created_by')->constrained('business_accounts'); // organizer or admin

            $table->string('code', 32);                    // stored uppercase
            $table->string('discount_type');               // flat | percent
            $table->unsignedInteger('discount_value');     // flat = cents off EACH ticket; percent = basis points (1500 = 15%)
            $table->string('usage_type');                  // one_time | multiple_time
            $table->unsignedInteger('max_redemptions')->nullable(); // one_time forces 1; null = unlimited
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};