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
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();

            // null = library template usable by any organizer; set = custom build requested by that organizer
            $table->foreignId('owner_organizer_id')->nullable()->constrained('organizers');

            $table->string('name');
            $table->string('kind');                                   // template | custom
            $table->string('status')->default('requested')->index();  // requested | in_progress | ready

            $table->string('blueprint_path')->nullable();  // organizer's uploaded blueprint (custom only)
            $table->string('svg_path')->nullable();        // the interactive map once built

            // "Mark floor plan ready" action; this is what unlocks the organizer's Bookable toggle
            $table->foreignId('ready_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->timestamp('ready_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floor_plans');
    }
};