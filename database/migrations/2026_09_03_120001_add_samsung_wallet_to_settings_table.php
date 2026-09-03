<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samsung Wallet credentials, alongside the existing Apple Wallet ones.
 *
 * Four identifiers, all issued by the Samsung Wallet Partner Portal
 * (partner.walletsvc.samsung.com) and none with a usable default — which is why
 * SamsungWalletService::isConfigured() requires all four and the buttons stay
 * hidden until they are set.
 *
 * The private key is encrypted at rest by the Setting model's Crypt accessor,
 * the same treatment the Apple P12 gets. It is the signing half of the key pair
 * whose PUBLIC half is uploaded to the Partner Portal; anyone holding it can
 * mint a card that Samsung will accept as ours.
 *
 * TWO THINGS HERE ARE NOT STYLE CHOICES:
 *
 * 1. Every string column is TEXT, not VARCHAR. The `settings` table is a single
 *    wide row that has been grown by every integration in this app, and its 81
 *    varchar columns already total ~64.7 KB of InnoDB's hard 65,535-byte row
 *    limit. Under utf8mb4 a varchar(255) costs 255 x 4 = 1,020 bytes against
 *    that budget, so there is not room for even one more — the first attempt at
 *    this migration died on exactly that (errno 1118). A TEXT column is counted
 *    as a ~20-byte pointer instead, which is why the same data fits. Any future
 *    string setting on this table has to be TEXT for the same reason.
 *
 * 2. Every add is guarded by hasColumn. The first run added
 *    `samsung_wallet_enabled` (a 1-byte tinyint, which fit) and then failed on
 *    the next column, so production is left with that column present and NO row
 *    in `migrations`. Without the guards, re-running dies on a duplicate column
 *    and the deploy stays stuck.
 */
return new class extends Migration
{
    /** column => the Blueprint method that creates it. */
    private const COLUMNS = [
        'samsung_wallet_enabled' => 'boolean',
        'samsung_wallet_org_name' => 'text',
        'samsung_wallet_partner_id' => 'text',
        'samsung_wallet_card_id' => 'text',
        'samsung_wallet_certificate_id' => 'text',
        'samsung_wallet_private_key' => 'text',
        'samsung_wallet_bg_color' => 'text',
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $type) {
                if (Schema::hasColumn('settings', $column)) {
                    continue;
                }

                if ($type === 'boolean') {
                    $table->boolean($column)->default(false);
                } else {
                    $table->text($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $present = array_filter(
            array_keys(self::COLUMNS),
            fn ($column) => Schema::hasColumn('settings', $column)
        );

        if ($present === []) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) use ($present) {
            $table->dropColumn(array_values($present));
        });
    }
};
