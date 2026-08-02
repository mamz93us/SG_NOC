<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Wallet pass background used to be forced to #1a1a2e on every settings save,
     * so existing installs carry that value even though nobody chose it — and a stored
     * value beats the new white brand default. Clear it only where it still holds the
     * old hardcoded colour, leaving any deliberate choice alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'wallet_pass_bg_color')) {
            return;
        }

        DB::table('settings')
            ->where('wallet_pass_bg_color', '#1a1a2e')
            ->update(['wallet_pass_bg_color' => '#ffffff']);
    }

    public function down(): void
    {
        // Not reversible: by now #ffffff may be a deliberate choice, and reverting it
        // would silently repaint a pass the admin actually wanted white.
    }
};
