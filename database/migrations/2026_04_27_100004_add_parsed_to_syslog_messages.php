<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a JSON `parsed` column for vendor-specific key-value payloads
 * (Sophos firewalls in particular send a structured KV string in the
 * message body). ParseSyslogPayloadsJob fills this column for rows
 * whose source_type has a registered parser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syslog_messages', function (Blueprint $table) {
            $table->json('parsed')->nullable()->after('raw');
        });
    }

    public function down(): void
    {
        Schema::table('syslog_messages', function (Blueprint $table) {
            $table->dropColumn('parsed');
        });
    }
};
