<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email verification (and the `verified` checkout requirement) arrived on 29 Oct 2026.
     * Accounts created before that never got a verification email, so they're treated as
     * verified instead of being blocked at checkout. Newer unverified accounts are left alone.
     */
    public const CUTOFF = '2026-10-29 00:00:00';

    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('created_at', '<', self::CUTOFF)
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    /**
     * Nothing to undo safely: we can't tell these apart from genuinely verified accounts.
     */
    public function down(): void {}
};
