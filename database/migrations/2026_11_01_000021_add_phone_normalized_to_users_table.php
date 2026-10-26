<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * users.phone stays as the customer typed it ("012-345 6789"); phone_normalized ("60123456789")
     * makes "one account per phone number" hold whatever the formatting. Kept in sync by the
     * User model (see User::normalizePhone()).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_normalized', 20)->nullable()->after('phone');
        });

        // Backfill. If two existing accounts share a number, the later one is left null rather
        // than failing the migration (and can fix it on the profile page).
        $seen = [];
        foreach (DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $user) {
            $normalized = User::normalizePhone($user->phone);

            if ($normalized !== null && ! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $normalized]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone_normalized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_normalized']);
            $table->dropColumn('phone_normalized');
        });
    }
};
