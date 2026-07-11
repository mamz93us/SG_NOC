<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee contact fields — the NOC employee profile becomes the source of
 * truth for these, editable in the UI and pushed to Azure AD on save.
 * (mobile_phone and extension_number already exist; extension stays contact-sourced.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_phone')->nullable()->after('mobile_phone');       // Azure businessPhones
            $table->string('office_location')->nullable()->after('work_phone');    // Azure officeLocation
            $table->string('city')->nullable()->after('office_location');          // Azure city
            $table->string('street_address')->nullable()->after('city');           // Azure streetAddress
            $table->string('company')->nullable()->after('street_address');        // Azure companyName
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['work_phone', 'office_location', 'city', 'street_address', 'company']);
        });
    }
};
