<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('rendered_html');
            $table->index('archived_at');
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('sent_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
