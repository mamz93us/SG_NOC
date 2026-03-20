<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->string('current_node_id', 50)->nullable()->after('current_step');
            $table->unsignedBigInteger('template_id')->nullable()->after('current_node_id');
            $table->foreign('template_id')->references('id')->on('workflow_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn(['current_node_id', 'template_id']);
        });
    }
};
