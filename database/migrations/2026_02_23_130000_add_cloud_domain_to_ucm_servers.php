<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ucm_servers', function (Blueprint $table) {
            $table->string('cloud_domain', 255)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('ucm_servers', function (Blueprint $table) {
            $table->dropColumn('cloud_domain');
        });
    }
};
