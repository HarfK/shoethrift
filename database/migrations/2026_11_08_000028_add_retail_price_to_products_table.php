<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original brand-new retail price of the shoe model (RM).
 * Used by the ML price recommendation feature - it is the strongest single
 * predictor of what a pre-loved pair is worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'retail_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('retail_price', 10, 2)->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'retail_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('retail_price');
            });
        }
    }
};
