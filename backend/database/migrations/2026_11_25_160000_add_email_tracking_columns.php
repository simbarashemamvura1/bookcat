<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set once the buyer's email (all tickets) has been sent. Null on a paid order = still owed.
            $table->timestamp('tickets_emailed_at')->nullable();
            $table->index(['status', 'tickets_emailed_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Set once an additional attendee's own email has been sent.
            $table->timestamp('attendee_emailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'tickets_emailed_at']);
            $table->dropColumn('tickets_emailed_at');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('attendee_emailed_at');
        });
    }
};