<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_email_templates', function (Blueprint $table) {
            $table->id();

            // Catalogue key from App\Support\EmailTemplates — a row only ever
            // overrides a template that already exists in code.
            $table->string('template_key', 100)->unique();

            $table->string('subject', 255)->nullable();
            $table->longText('body_html')->nullable();

            // Off = keep the row but send the original design. Lets an edit be
            // parked without losing it.
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_email_templates');
    }
};
