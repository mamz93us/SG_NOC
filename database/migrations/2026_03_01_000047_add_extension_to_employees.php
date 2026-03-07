<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('extension_number', 20)->nullable()->after('notes');
            $table->unsignedBigInteger('ucm_server_id')->nullable()->after('extension_number');
            $table->foreign('ucm_server_id')->references('id')->on('ucm_servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['ucm_server_id']);
            $table->dropColumn(['extension_number', 'ucm_server_id']);
        });
    }
};
