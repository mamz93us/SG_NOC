<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'storage_location')) {
                $table->string('storage_location', 100)->nullable()->after('location_description');
            }
            if (!Schema::hasColumn('devices', 'scrap_workflow_id')) {
                $table->unsignedBigInteger('scrap_workflow_id')->nullable()->after('storage_location');
                $table->index('scrap_workflow_id');
            }
        });

        DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM(
            'active','available','assigned','maintenance','retired','scrapped'
        ) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM(
            'active','available','assigned','maintenance','retired'
        ) NOT NULL DEFAULT 'active'");

        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'scrap_workflow_id')) {
                $table->dropIndex(['scrap_workflow_id']);
                $table->dropColumn('scrap_workflow_id');
            }
            if (Schema::hasColumn('devices', 'storage_location')) {
                $table->dropColumn('storage_location');
            }
        });
    }
};
