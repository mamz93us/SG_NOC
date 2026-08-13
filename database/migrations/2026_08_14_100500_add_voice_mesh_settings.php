<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'voice_mesh_secret')) {
                // Encrypted by the Setting mutator, like sftpgo_webhook_secret.
                $table->text('voice_mesh_secret')->nullable();
            }
            if (! Schema::hasColumn('settings', 'voice_mesh_retention_days')) {
                $table->unsignedSmallInteger('voice_mesh_retention_days')->default(30);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['voice_mesh_secret', 'voice_mesh_retention_days'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
