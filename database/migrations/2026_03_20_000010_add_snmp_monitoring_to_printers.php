<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            // ── SNMP Monitoring Fields ─────────────────────────
            $table->boolean('snmp_enabled')->default(false)->after('snmp_version');
            $table->timestamp('snmp_last_polled_at')->nullable()->after('snmp_enabled');

            // ── Toner Levels (percentage 0-100, -1=unknown, -2=not applicable, -3=some remaining) ──
            $table->smallInteger('toner_black')->nullable()->after('snmp_last_polled_at');
            $table->smallInteger('toner_cyan')->nullable()->after('toner_black');
            $table->smallInteger('toner_magenta')->nullable()->after('toner_cyan');
            $table->smallInteger('toner_yellow')->nullable()->after('toner_magenta');
            $table->smallInteger('toner_waste')->nullable()->after('toner_yellow');

            // ── Supply Levels ──────────────────────────────────
            $table->smallInteger('drum_black')->nullable()->after('toner_waste');
            $table->smallInteger('drum_color')->nullable()->after('drum_black');
            $table->smallInteger('fuser_level')->nullable()->after('drum_color');

            // ── Paper Trays ────────────────────────────────────
            $table->json('paper_trays')->nullable()->after('fuser_level');
            // Format: [{"name":"Tray 1","current":250,"max":500},{"name":"Tray 2","current":0,"max":250}]

            // ── Page Counters ──────────────────────────────────
            $table->unsignedBigInteger('page_count_total')->nullable()->after('paper_trays');
            $table->unsignedBigInteger('page_count_color')->nullable()->after('page_count_total');
            $table->unsignedBigInteger('page_count_mono')->nullable()->after('page_count_color');
            $table->unsignedBigInteger('page_count_copy')->nullable()->after('page_count_mono');
            $table->unsignedBigInteger('page_count_print')->nullable()->after('page_count_copy');
            $table->unsignedBigInteger('page_count_scan')->nullable()->after('page_count_print');
            $table->unsignedBigInteger('page_count_fax')->nullable()->after('page_count_scan');

            // ── Printer Status ─────────────────────────────────
            $table->string('printer_status', 30)->nullable()->after('page_count_fax');
            // Values: idle, printing, warmup, unknown, error
            $table->string('error_state', 100)->nullable()->after('printer_status');
            // Values: normal, low_paper, no_paper, low_toner, no_toner, door_open, jammed, offline, service_needed
            $table->string('snmp_sys_description')->nullable()->after('error_state');
            $table->string('snmp_model')->nullable()->after('snmp_sys_description');
            $table->string('snmp_serial')->nullable()->after('snmp_model');

            // ── Alert Thresholds ───────────────────────────────
            $table->unsignedSmallInteger('toner_warning_threshold')->default(20)->after('snmp_serial');
            $table->unsignedSmallInteger('toner_critical_threshold')->default(5)->after('toner_warning_threshold');
            $table->unsignedSmallInteger('paper_warning_threshold')->default(15)->after('toner_critical_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn([
                'snmp_enabled', 'snmp_last_polled_at',
                'toner_black', 'toner_cyan', 'toner_magenta', 'toner_yellow', 'toner_waste',
                'drum_black', 'drum_color', 'fuser_level',
                'paper_trays',
                'page_count_total', 'page_count_color', 'page_count_mono',
                'page_count_copy', 'page_count_print', 'page_count_scan', 'page_count_fax',
                'printer_status', 'error_state', 'snmp_sys_description', 'snmp_model', 'snmp_serial',
                'toner_warning_threshold', 'toner_critical_threshold', 'paper_warning_threshold',
            ]);
        });
    }
};
