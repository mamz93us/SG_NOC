<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 50);                    // ec74d7800474 (no colons, lowercase)
            $table->unsignedTinyInteger('account_index'); // 1, 2, 3 ...
            $table->string('sip_user_id', 100)->nullable(); // e.g. "1401"
            $table->string('sip_server', 255)->nullable();  // e.g. "192.168.1.5"
            $table->string('account_status', 50)->nullable(); // Registered / Unregistered
            $table->boolean('is_local')->default(false);    // true = local account
            $table->timestamp('fetched_at')->nullable();    // last GDMS fetch time
            $table->timestamps();

            $table->unique(['mac', 'account_index']);
            $table->index('mac');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_accounts');
    }
};
