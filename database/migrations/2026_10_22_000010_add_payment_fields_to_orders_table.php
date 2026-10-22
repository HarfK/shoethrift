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
        Schema::table('orders', function (Blueprint $table) {
            // 'online' (HitPay) or 'cod'. Null for orders placed before payments existed.
            $table->string('payment_method', 20)->nullable()->after('status')->index();
            // HitPay payment request id; the one that was paid, once paid.
            $table->string('gateway_reference')->nullable()->after('payment_method')->index();
            $table->string('gateway_payment_id')->nullable()->after('gateway_reference');
            $table->string('payment_url', 1024)->nullable()->after('gateway_payment_id');
            // Online orders still unpaid at this time are cancelled by orders:expire-unpaid.
            $table->timestamp('payment_expires_at')->nullable()->after('payment_url')->index();
            $table->timestamp('paid_at')->nullable()->after('payment_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['gateway_reference']);
            $table->dropIndex(['payment_expires_at']);
            $table->dropColumn(['payment_method', 'gateway_reference', 'gateway_payment_id', 'payment_url', 'payment_expires_at', 'paid_at']);
        });
    }
};
