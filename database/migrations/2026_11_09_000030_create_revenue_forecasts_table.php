<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved daily revenue forecasts, so they can be compared with what was actually sold.
     */
    public function up(): void
    {
        Schema::create('revenue_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('forecast_date'); // the Malaysian day being forecast
            $table->date('made_on');       // the Malaysian day the forecast was made
            $table->decimal('forecast', 12, 2);
            $table->decimal('lower', 12, 2)->nullable();
            $table->decimal('upper', 12, 2)->nullable();
            $table->boolean('is_backfill')->default(false); // re-created later, not saved at the time
            $table->string('model')->nullable();
            $table->date('trained_until')->nullable();
            $table->timestamps();

            $table->unique(['forecast_date', 'made_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_forecasts');
    }
};
