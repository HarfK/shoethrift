<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Thrift-specific details. All optional, so existing products show "Not specified".
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('material', 30)->nullable()->after('color');
            $table->decimal('insole_length_cm', 4, 1)->nullable()->after('material');
            $table->enum('sole_condition', ['excellent', 'good', 'fair', 'worn'])->nullable()->after('insole_length_cm');
            $table->boolean('box_included')->default(false)->after('sole_condition');
            $table->text('wear_notes')->nullable()->after('box_included');
            $table->text('authenticity_notes')->nullable()->after('wear_notes');

            $table->index('material');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['material']);
            $table->dropColumn(['material', 'insole_length_cm', 'sole_condition', 'box_included', 'wear_notes', 'authenticity_notes']);
        });
    }
};
