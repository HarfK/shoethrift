<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One return request per order: requested → approved → refunded, or rejected.
     */
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['requested', 'approved', 'rejected', 'refunded'])->default('requested');
            $table->enum('reason', ['doesnt_fit', 'not_as_described', 'damaged', 'wrong_item', 'changed_mind', 'other']);
            $table->text('details');
            $table->text('admin_note')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->enum('refund_method', ['hitpay', 'manual'])->nullable();
            $table->string('refund_reference')->nullable();
            $table->timestamp('refund_attempted_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->boolean('restocked')->default(false);
            $table->timestamps();
        });

        Schema::create('return_request_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_request_photos');
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
    }
};
