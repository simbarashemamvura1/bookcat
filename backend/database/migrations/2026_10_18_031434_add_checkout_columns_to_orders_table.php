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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();        // end of the payment window; pending orders past it get cancelled
            $table->string('cart_token_hash', 64)->nullable()->index();  // sha256 of the hold token, never the token itself
        });

        // At most one pending order per cart, even if two requests race.
        DB::statement("CREATE UNIQUE INDEX orders_cart_pending_unique ON orders (cart_token_hash) WHERE status = 'pending_payment'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'cart_token_hash']);
        });
    }
};