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
        Schema::create('event_financial_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();

            // Concrete values copied from the defaults at event creation, never "null = use default",
            // so changing platform defaults later can't silently alter an existing event's deal.
            $table->unsignedInteger('creation_fee_cents');
            $table->unsignedInteger('ticket_fee_flat_cents');
            $table->unsignedSmallInteger('ticket_fee_bps');
            $table->unsignedSmallInteger('processing_fee_bps');
            $table->unsignedInteger('minimum_charge_cents');
            $table->boolean('reserve_enabled')->default(false);
            $table->unsignedSmallInteger('reserve_bps')->nullable();
            $table->text('reserve_notes')->nullable();
            $table->unsignedSmallInteger('payout_delay_business_days');

            // Audit trail for the override_financial_terms permission
            $table->foreignId('overridden_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_financial_terms');
    }
};