<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit/track async GDMS device tasks (reboot, factory reset, config push,
 * account assignment, firmware upgrade). GDMS executes these asynchronously,
 * so we keep a local record of what was requested, by whom, and the outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gdms_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 20)->nullable()->index();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('task_type', 30);            // reboot|factory_reset|config_push|assign_account|upgrade
            $table->string('gdms_task_id')->nullable(); // task id returned by GDMS, if any
            $table->string('status', 20)->default('queued'); // queued|sent|success|failed
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdms_tasks');
    }
};
