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
        Schema::create('organizer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('type'); // government_id | business_registration | venue_contract | bank_details
            $table->string('path');
            $table->string('original_name');
            $table->string('status')->default('pending'); // pending | accepted | rejected
            $table->text('review_note')->nullable();
            $table->timestamps();

            // Not unique: a resubmission after "changes requested" adds a new row.
            $table->index(['organizer_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizer_documents');
    }
};