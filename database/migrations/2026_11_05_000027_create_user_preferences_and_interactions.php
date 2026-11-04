<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal recommendations (App\Services\Recommendations\PersonalScore):
     * - user_preferences: the shoe size, brands and budget a customer saves on their profile.
     * - user_interactions: what a customer viewed, added to cart and bought.
     */
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('shoe_size', 3, 1)->nullable();
            $table->json('preferred_brands')->nullable();
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('user_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['view', 'cart', 'purchase']);
            $table->string('source', 30)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_interactions');
        Schema::dropIfExists('user_preferences');
    }
};
