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
     * - delivered_at: when the customer received the order (starts the return window).
     * - refunded_amount: total refunded through return requests.
     * - "refunded" status: the whole paid amount was refunded.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'shipped', 'delivered', 'completed', 'cancelled', 'failed', 'refunded'])
                ->default('pending')
                ->change();
            $table->timestamp('delivered_at')->nullable()->after('paid_at');
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('total');
        });

        // Orders that were already delivered: best available date is their last update.
        DB::table('orders')
            ->whereIn('status', ['delivered', 'completed'])
            ->update(['delivered_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->where('status', 'refunded')->update(['status' => 'completed']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'refunded_amount']);
            $table->enum('status', ['pending', 'paid', 'shipped', 'delivered', 'completed', 'cancelled', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
