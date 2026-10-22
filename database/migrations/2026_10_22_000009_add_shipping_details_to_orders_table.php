<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The receiver's details and the shipping fee are saved on each order, so later profile
     * or fee changes never rewrite past orders. orders.total includes shipping_fee.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_name', 100)->nullable()->after('status');
            $table->string('shipping_phone', 30)->nullable()->after('shipping_name');
            $table->decimal('shipping_fee', 10, 2)->default(0)->after('total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_name', 'shipping_phone', 'shipping_fee']);
        });
    }
};
