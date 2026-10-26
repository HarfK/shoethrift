<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cash on Delivery: pending → shipped → delivered → completed (cash collected).
     * delivery_failed_at marks a COD order cancelled because the delivery failed
     * (counted per customer to block COD after repeated failures).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'shipped', 'delivered', 'completed', 'cancelled', 'failed'])
                ->default('pending')
                ->change();
            $table->timestamp('delivery_failed_at')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->where('status', 'delivered')->update(['status' => 'shipped']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_failed_at');
            $table->enum('status', ['pending', 'paid', 'shipped', 'completed', 'cancelled', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
