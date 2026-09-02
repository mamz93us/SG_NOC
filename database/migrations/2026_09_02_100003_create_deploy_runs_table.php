<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per terminal session or command press.
 *
 * `mode=shell` is an interactive SSH session (no command); `mode=exec` is a
 * button press, whose transcript and exit code are POSTed back by the Node
 * proxy — not the browser — so a closed tab still produces a complete record.
 *
 * status/mode are plain strings, not enums: `device_ssh_sessions` uses an enum
 * and DeviceSshController writes a value that isn't in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_server_id')->constrained('deploy_servers')->cascadeOnDelete();
            $table->foreignId('deploy_command_id')->nullable()->constrained('deploy_commands')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('mode', 16)->default('exec')->comment('exec | shell');
            $table->string('status', 16)->default('running')->comment('running | success | failed | timeout | aborted');
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();

            $table->string('command_label', 100)->nullable()->comment('Snapshot of the command name, survives deletion');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->string('client_ip', 45)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['deploy_server_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_runs');
    }
};
