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
        Schema::create('floor_plan_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_plan_id')->constrained('floor_plans')->cascadeOnDelete();
            $table->string('seat_key', 64);        // e.g. ORCH-C-12
            $table->string('section', 64);
            $table->string('row_label', 16)->nullable();
            $table->string('seat_number', 16);

            $table->unique(['floor_plan_id', 'seat_key']);
            $table->index(['floor_plan_id', 'section']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floor_plan_seats');
    }
};