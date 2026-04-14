<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cups_printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('queue_name', 100)->unique();
            $table->string('ip_address', 45);
            $table->unsignedSmallInteger('port')->default(631);
            $table->string('protocol', 20)->default('ipp');
            $table->string('ipp_path')->default('/ipp/print');
            $table->unsignedInteger('branch_id')->nullable();
            $table->string('driver')->default('everywhere');
            $table->string('location')->nullable();
            $table->boolean('is_shared')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('status', 30)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cups_printers');
    }
};
