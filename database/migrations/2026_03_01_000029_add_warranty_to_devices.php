<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->date('purchase_date')->nullable()->after('notes');
            $table->date('warranty_expiry')->nullable()->after('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['purchase_date', 'warranty_expiry']);
        });
    }
};
