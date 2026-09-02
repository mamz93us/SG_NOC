<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buttons on a deployment server's page. Every server has different deploy
 * steps, so the list is per-server and free-form: the body may be an inline
 * snippet (`git pull && composer install …`) or a call to a script that lives
 * on the target (`/opt/sg/deploy.sh`).
 *
 * The browser never sends a command string — it names a row here and the
 * controller reads the body server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_server_id')->constrained('deploy_servers')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 16)->default('custom')->comment('deploy | logs | restart | status | custom — button colour/icon only');
            $table->text('command');
            $table->string('working_directory', 255)->nullable()->comment('Overrides the server default');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->boolean('confirm_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['deploy_server_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_commands');
    }
};
