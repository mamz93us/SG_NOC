<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('email_marketing_enabled')->default(false);
            $table->string('ses_region', 32)->nullable();
            $table->string('ses_access_key_id', 128)->nullable();
            $table->text('ses_secret_access_key')->nullable();
            $table->string('ses_configuration_set', 128)->nullable();
            $table->string('ses_default_from_email', 191)->nullable();
            $table->string('ses_default_from_name', 191)->nullable();
            $table->string('ses_default_reply_to', 191)->nullable();
            $table->unsignedInteger('ses_throttle_per_second')->nullable();
            $table->string('ses_unsubscribe_base_url', 255)->nullable();
            $table->string('sns_topic_arn', 255)->nullable();
            $table->unsignedInteger('email_marketing_event_retention_days')->default(365);
            $table->boolean('email_marketing_open_pixel_enabled')->default(true);
            $table->boolean('email_marketing_click_tracking_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'email_marketing_enabled',
                'ses_region',
                'ses_access_key_id',
                'ses_secret_access_key',
                'ses_configuration_set',
                'ses_default_from_email',
                'ses_default_from_name',
                'ses_default_reply_to',
                'ses_throttle_per_second',
                'ses_unsubscribe_base_url',
                'sns_topic_arn',
                'email_marketing_event_retention_days',
                'email_marketing_open_pixel_enabled',
                'email_marketing_click_tracking_enabled',
            ]);
        });
    }
};
