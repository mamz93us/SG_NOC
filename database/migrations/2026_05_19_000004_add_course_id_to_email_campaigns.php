<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            // When set, the campaign delivers per-recipient course certificates.
            // CampaignDispatcher resolves recipients from course_certificates instead
            // of list/segment, and MergeTagRenderer exposes {{certificate_url}}.
            $table->foreignId('course_id')->nullable()->after('email_segment_id')
                ->constrained('courses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
