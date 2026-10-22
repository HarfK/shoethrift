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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('brand', 100)->index();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->enum('condition', ['like_new', 'good', 'fair']);
            // UK shoe size, whole or half sizes (e.g. 8.0, 8.5). Numeric so "same size" matching is exact.
            $table->decimal('size', 3, 1)->index();
            $table->string('color', 50)->nullable();
            $table->unsignedInteger('stock')->default(1);
            $table->enum('status', ['available', 'sold', 'hidden'])->default('available')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
