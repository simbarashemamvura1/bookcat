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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('provider', 32);                  // fake | stripe
            $table->string('provider_reference')->unique();  // payment intent id; also the webhook idempotency key
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status')->default('pending');    // pending | succeeded | failed | refund_pending | refunded
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('refund_reason')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};