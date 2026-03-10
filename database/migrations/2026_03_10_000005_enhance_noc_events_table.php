<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable()->after('entity_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->integer('cooldown_minutes')->default(0)->after('source_id');

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id', 'cooldown_minutes']);
        });
    }
};
