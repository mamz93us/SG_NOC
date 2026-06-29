<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('wallet_pass_enabled')->default(false);
            $table->string('wallet_pass_org_name')->nullable();
            $table->string('wallet_pass_team_id', 32)->nullable();
            $table->string('wallet_pass_type_id')->nullable();
            // Encrypted at the model layer (Crypt accessors/mutators)
            $table->text('wallet_pass_cert')->nullable();
            $table->text('wallet_pass_cert_password')->nullable();
            $table->text('wallet_pass_wwdr_cert')->nullable();
            $table->string('wallet_pass_bg_color', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_pass_enabled',
                'wallet_pass_org_name',
                'wallet_pass_team_id',
                'wallet_pass_type_id',
                'wallet_pass_cert',
                'wallet_pass_cert_password',
                'wallet_pass_wwdr_cert',
                'wallet_pass_bg_color',
            ]);
        });
    }
};
