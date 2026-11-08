<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loyalty points (App\Services\Loyalty\PointsService).
     *
     * point_transactions is the ledger: one row per change, `points` signed, `balance_after` the customer's
     * balance after it. Rows that add points (earn, return_redeem, positive adjust) are "lots": they carry
     * `expires_at` and `remaining` (points of the lot not yet used or expired), so points are used and expire
     * oldest first (FIFO). Rows that remove points record which lots they took from in `allocations`.
     */
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['earn', 'redeem', 'reverse_earn', 'return_redeem', 'expire', 'adjust']);
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('description');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('remaining')->nullable();
            $table->json('allocations')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index(['user_id', 'expires_at']);
            $table->index(['order_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_redeemed')->default(0)->after('refunded_amount');
            $table->decimal('points_discount', 10, 2)->default(0)->after('points_redeemed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['points_redeemed', 'points_discount']);
        });
        Schema::dropIfExists('point_transactions');
    }
};
