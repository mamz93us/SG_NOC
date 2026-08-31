<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings for the "Create Ticket" page (addTicketingRequestForNOC).
 *
 * Deliberately separate from the existing ticketing_api_* columns: those point
 * at provisionNewEmployee, a different endpoint on a different host, called by
 * the onboarding workflow. Sharing one URL column would mean one of the two
 * features is always pointed at the wrong path.
 *
 * The key falls back to ticketing_api_key when blank — same ticketing system,
 * usually the same X-API-Key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('noc_ticket_api_url', 500)->nullable()->after('ticketing_api_enabled');
            $table->text('noc_ticket_api_key')->nullable()->after('noc_ticket_api_url');
            $table->boolean('noc_ticket_api_enabled')->default(false)->after('noc_ticket_api_key');
            // Category / subcategory / type / priority lookups. The API takes
            // bare numeric IDs and exposes no list endpoint, so the ID→label
            // map has to live here or nobody can fill the form.
            $table->json('noc_ticket_catalog')->nullable()->after('noc_ticket_api_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'noc_ticket_api_url',
                'noc_ticket_api_key',
                'noc_ticket_api_enabled',
                'noc_ticket_catalog',
            ]);
        });
    }
};
