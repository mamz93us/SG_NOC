<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->enum('step_type', ['approval', 'action', 'condition', 'notification', 'wait'])
                  ->default('approval')
                  ->after('comments');
            $table->json('step_config')->nullable()->after('step_type');
            $table->string('node_id', 50)->nullable()->after('step_config');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['step_type', 'step_config', 'node_id']);
        });
    }
};
