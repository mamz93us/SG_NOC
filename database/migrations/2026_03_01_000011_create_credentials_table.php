<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('username')->nullable();
            $table->text('password');              // always encrypted via Laravel Crypt
            $table->string('url')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->enum('category', ['admin', 'api', 'snmp', 'user', 'service', 'other'])->default('other')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
