<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_users', function (Blueprint $table) {
            $table->string('phone_number', 50)->nullable()->after('job_title');
            $table->string('mobile_phone', 50)->nullable()->after('phone_number');
            $table->string('office_location', 100)->nullable()->after('mobile_phone');
            $table->string('company_name', 255)->nullable()->after('office_location');
            $table->string('street_address', 255)->nullable()->after('company_name');
            $table->string('city', 100)->nullable()->after('street_address');
            $table->string('postal_code', 20)->nullable()->after('city');
            $table->string('country', 100)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('identity_users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_number', 'mobile_phone', 'office_location', 'company_name',
                'street_address', 'city', 'postal_code', 'country',
            ]);
        });
    }
};
