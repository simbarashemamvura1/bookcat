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
        Schema::create('event_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('floor_plan_seat_id')->nullable()->constrained('floor_plan_seats')->nullOnDelete();

            // Copied from the floor plan so later floor plan edits can't touch this event.
            $table->string('seat_key', 64);
            $table->string('section', 64);
            $table->string('row_label', 16)->nullable();
            $table->string('seat_number', 16);

            $table->unsignedInteger('price_cents')->nullable(); // null = not priced yet, 0 = free
            $table->string('status')->default('available');     // available | blocked | sold
            $table->string('block_note')->nullable();             // organizer hold note (#31)
            $table->timestamps();

            $table->unique(['event_id', 'seat_key']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'section']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_seats');
    }
};