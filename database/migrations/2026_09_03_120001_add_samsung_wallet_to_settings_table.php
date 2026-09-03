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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('samsung_wallet_enabled')->default(false);
            $table->string('samsung_wallet_org_name')->nullable();
            $table->string('samsung_wallet_partner_id', 64)->nullable();
            $table->string('samsung_wallet_card_id', 64)->nullable();
            $table->string('samsung_wallet_certificate_id', 64)->nullable();
            // Encrypted at the model layer (Crypt accessor/mutator).
            $table->text('samsung_wallet_private_key')->nullable();
            $table->string('samsung_wallet_bg_color', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'samsung_wallet_enabled',
                'samsung_wallet_org_name',
                'samsung_wallet_partner_id',
                'samsung_wallet_card_id',
                'samsung_wallet_certificate_id',
                'samsung_wallet_private_key',
                'samsung_wallet_bg_color',
            ]);
        });
    }
};
