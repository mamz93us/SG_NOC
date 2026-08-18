<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Cloud API credentials, configured in the UI rather than .env —
 * same pattern as Sophos Central, SFTPGo and the Graph credentials.
 *
 * `whatsapp_template_body_params` is a comma-separated ordered list of tokens
 * (title, message, severity, link, time) mapped onto the approved template's
 * body placeholders, so a template of any shape can be used without a code
 * change. Meta only allows free-form text inside a 24-hour customer-service
 * window, which is why template mode is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_api_version', 10)->nullable();
            $table->string('whatsapp_phone_number_id', 64)->nullable();
            $table->string('whatsapp_business_account_id', 64)->nullable();
            $table->text('whatsapp_access_token')->nullable();
            $table->boolean('whatsapp_use_template')->default(true);
            $table->string('whatsapp_alert_template', 128)->nullable();
            $table->string('whatsapp_template_language', 16)->nullable();
            $table->string('whatsapp_template_body_params', 128)->nullable();
            $table->string('whatsapp_default_country_code', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_enabled',
                'whatsapp_api_version',
                'whatsapp_phone_number_id',
                'whatsapp_business_account_id',
                'whatsapp_access_token',
                'whatsapp_use_template',
                'whatsapp_alert_template',
                'whatsapp_template_language',
                'whatsapp_template_body_params',
                'whatsapp_default_country_code',
            ]);
        });
    }
};
