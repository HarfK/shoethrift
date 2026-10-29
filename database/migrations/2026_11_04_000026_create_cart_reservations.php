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
     * Timed cart holds (App\Services\Cart\Reservations):
     * - cart_items.reserved_until: each cart line IS the reservation of its pairs until then.
     * - cart_releases: holds that ended without an order (expired / bought by another customer /
     *   no longer for sale), so the cart can say exactly why, and admins see repeated expiries.
     * - product_watches: "Notify me when it's free".
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->timestamp('reserved_until')->nullable()->after('quantity');
            $table->index(['product_id', 'reserved_until']);
            $table->index('reserved_until');
        });

        // Lines that existed before holds: give them one normal hold from now.
        DB::table('cart_items')->whereNull('reserved_until')
            ->update(['reserved_until' => now()->addMinutes((int) config('shop.cart.hold_minutes', 15))]);

        Schema::create('cart_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->enum('reason', ['expired', 'bought_by_other', 'no_longer_for_sale']);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('released_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'notified_at']);
            $table->index(['reason', 'released_at']);
        });

        Schema::create('product_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_watches');
        Schema::dropIfExists('cart_releases');

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'reserved_until']);
            $table->dropIndex(['reserved_until']);
            $table->dropColumn('reserved_until');
        });
    }
};
