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
        Schema::create('organizers', function (Blueprint $table) {
            $table->id();

            // One login per organizer for v1, so this is one-to-one.
            $table->foreignId('business_account_id')->unique()->constrained('business_accounts')->cascadeOnDelete();

            // Public profile (not visible until approved)
            $table->string('business_name');
            $table->string('slug')->nullable()->unique();
            $table->text('bio')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('contact_phone')->nullable();

            // How the account came to exist
            $table->string('origin')->default('self_service'); // self_service | sales_assisted | pre_approved

            // Application review lifecycle
            $table->string('application_status')->default('pending_review')->index(); // pending_review | changes_requested | approved | rejected
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Admin "flag/pause" (final suspension is Super Admin and lives on business_accounts.status)
            $table->foreignId('flagged_by')->nullable()->constrained('business_accounts')->nullOnDelete();
            $table->timestamp('flagged_at')->nullable();
            $table->text('flag_reason')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizers');
    }
};