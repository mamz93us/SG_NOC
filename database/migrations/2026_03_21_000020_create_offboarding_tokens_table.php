<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('manager_email')->nullable();
            $table->string('manager_name')->nullable();
            $table->json('payload')->nullable();          // snapshot of HR request data
            $table->text('manager_notes')->nullable();    // filled in by manager on the form
            $table->enum('manager_decision', ['approved', 'rejected'])->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflow_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_tokens');
    }
};
