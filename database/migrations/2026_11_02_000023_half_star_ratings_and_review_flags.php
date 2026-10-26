<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - rating: half stars (0.5 to 5.0), decimal(2,1). Existing whole-star ratings keep their value.
     * - edited_at: the customer edited the review (shown as "Edited …").
     * - flagged_at / flag_reasons: needs an admin's look (Review::flagReasons()); cleared by "Mark as checked".
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->decimal('rating', 2, 1)->change();
            $table->timestamp('edited_at')->nullable()->after('hidden_at');
            $table->timestamp('flagged_at')->nullable()->after('edited_at');
            $table->json('flag_reasons')->nullable()->after('flagged_at');

            $table->index('flagged_at');
        });
    }

    /**
     * Reverse the migrations. Half stars are rounded down to whole stars (minimum 1).
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['flagged_at']);
            $table->dropColumn(['edited_at', 'flagged_at', 'flag_reasons']);
        });

        foreach (DB::table('reviews')->get(['id', 'rating']) as $review) {
            DB::table('reviews')->where('id', $review->id)->update(['rating' => max(1, (int) floor((float) $review->rating))]);
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->change();
        });
    }
};
