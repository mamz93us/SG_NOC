<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_deploy_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('printer_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('sent_to_email');
            $table->json('printer_config')->nullable();   // ip, name, driver, share_name snapshot
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('printer_id')->references('id')->on('printers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_deploy_tokens');
    }
};
