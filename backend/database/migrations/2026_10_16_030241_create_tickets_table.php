<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('event_id')->constrained('events');

            // Nullable by design (decision log): standing admission may add tickets with no seat later.
            $table->foreignId('event_seat_id')->nullable()->constrained('event_seats');
            $table->string('admission_type')->default('seated'); // seated | standing

            $table->string('token', 64)->unique();   // what the QR encodes: unguessable, never a sequential id
            $table->string('kind');                  // paid | comp
            $table->string('status')->default('valid'); // valid | refunded

            // One entry per ticket: the buyer's own seat and every Additional Attendee
            $table->string('attendee_name');
            $table->string('attendee_email')->nullable();
            $table->boolean('is_buyer_seat')->default(false);

            // Money snapshot taken at purchase time, so later fee overrides never rewrite history.
            $table->unsignedInteger('list_price_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('price_paid_cents');    // list - discount
            $table->unsignedInteger('bookcat_fee_cents');
            $table->unsignedInteger('processing_fee_cents');

            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });

        // The database-level backstop against double-booking: a seat can have at most one VALID
        // ticket, even if the Redis hold layer fails. A refunded ticket frees the seat for resale.
        DB::statement("CREATE UNIQUE INDEX tickets_event_seat_active_unique ON tickets (event_seat_id) WHERE status = 'valid'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};