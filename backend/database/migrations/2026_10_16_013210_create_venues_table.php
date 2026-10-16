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
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address_line');
            $table->string('city');
            $table->string('region');            // province / state
            $table->string('country', 2);        // CA | US
            $table->string('postal_code')->nullable();
            $table->string('timezone');          // IANA zone; needed because launch spans CA + US

            // null = platform-wide venue (Admin-created); set = requested by and private to one organizer
            $table->foreignId('owner_organizer_id')->nullable()->constrained('organizers');
            $table->foreignId('created_by')->constrained('business_accounts');
            $table->string('status')->default('pending_review')->index(); // pending_review | approved | rejected

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};