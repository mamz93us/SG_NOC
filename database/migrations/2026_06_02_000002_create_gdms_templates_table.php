<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local cache of GDMS configuration templates so the template-manager UI can
 * list/search them without hitting the GDMS API on every page load. Refreshed
 * by the gdms:sync-templates command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gdms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('gdms_template_id')->unique();
            $table->string('name');
            $table->string('type', 20)->default('model'); // model|group|site
            $table->string('model')->nullable();          // device model for model-templates
            $table->string('scope_ref')->nullable();       // site id / group id for site/group templates
            $table->longText('raw')->nullable();           // JSON of the template's parameter map
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdms_templates');
    }
};
