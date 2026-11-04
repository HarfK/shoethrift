<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer notifications: when the "your hold expires soon" message was sent for a cart line,
     * so each hold is warned only once (App\Services\Cart\HoldWarnings).
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->timestamp('expiry_warned_at')->nullable()->after('reserved_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('expiry_warned_at');
        });
    }
};
