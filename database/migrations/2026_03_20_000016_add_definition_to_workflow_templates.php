<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->json('definition')->nullable()->after('approval_chain');
            $table->string('trigger_event', 100)->nullable()->after('definition');
            $table->unsignedInteger('version')->default(1)->after('trigger_event');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropColumn(['definition', 'trigger_event', 'version']);
        });
    }
};
