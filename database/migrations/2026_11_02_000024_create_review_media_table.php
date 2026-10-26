<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Photos (up to 5) and one optional video per review. Files live on the public disk under
     * reviews/{review_id}/; a hidden item's file is moved to the private local disk (ReviewMedia::hide()).
     */
    public function up(): void
    {
        Schema::create('review_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['photo', 'video']);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->decimal('duration_seconds', 6, 2)->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_media');
    }
};
