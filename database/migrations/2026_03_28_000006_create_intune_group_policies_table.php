<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intune_group_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intune_group_id')->constrained()->cascadeOnDelete();
            $table->string('policy_type', 80);                    // e.g. 'printer_script'
            $table->string('intune_policy_id', 100)->nullable();  // script ID returned by Graph API
            $table->string('policy_name', 150);
            $table->json('policy_payload');                       // printer_id, ip, driver, etc.
            $table->enum('status', ['pending', 'assigned', 'error'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intune_group_policies');
    }
};
