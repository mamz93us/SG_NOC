<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_signature_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('domain', 100)->nullable()->index();   // null = all domains
            $table->enum('type', ['new_email', 'reply', 'all'])->default('all');
            $table->string('logo_url', 500)->nullable();
            $table->string('primary_color', 20)->default('#d81f2a');
            $table->longText('html_body');
            $table->text('plain_text_body')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_signature_templates');
    }
};
