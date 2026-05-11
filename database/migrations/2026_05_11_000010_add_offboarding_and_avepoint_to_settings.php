<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // ── AvePoint Graph API ────────────────────────────────────────
            $table->boolean('avepoint_enabled')->default(false)->after('ticketing_api_enabled');
            $table->string('avepoint_base_url', 255)->nullable()->after('avepoint_enabled');
            $table->string('avepoint_tenant_id', 100)->nullable()->after('avepoint_base_url');
            $table->string('avepoint_client_id', 100)->nullable()->after('avepoint_tenant_id');
            $table->text('avepoint_client_secret')->nullable()->after('avepoint_client_id');
            $table->string('avepoint_region', 20)->nullable()->after('avepoint_client_secret');
            $table->string('avepoint_export_endpoint', 255)->nullable()->after('avepoint_region');
            $table->string('avepoint_download_endpoint', 255)->nullable()->after('avepoint_export_endpoint');

            // ── Azure Blob (offboarding backup archive) ──────────────────
            $table->boolean('azure_blob_enabled')->default(false)->after('avepoint_download_endpoint');
            $table->string('azure_blob_account', 100)->nullable()->after('azure_blob_enabled');
            $table->string('azure_blob_container', 100)->nullable()->after('azure_blob_account');
            $table->text('azure_blob_key')->nullable()->after('azure_blob_container');
            $table->string('azure_blob_endpoint_suffix', 100)->default('core.windows.net')->after('azure_blob_key');

            // ── Offboarding behavior ─────────────────────────────────────
            $table->boolean('offboarding_enabled')->default(false)->after('azure_blob_endpoint_suffix');
            $table->string('offboarding_group_id', 100)->nullable()->after('offboarding_enabled')
                  ->comment('Azure group GUID to add offboarded users to (sign-in lockdown).');
            $table->string('offboarding_exchange_only_sku', 100)->nullable()->after('offboarding_group_id')
                  ->comment('Exchange Plan 1 SKU GUID — assigned when manager picks "forward".');
            $table->unsignedSmallInteger('offboarding_retention_days')->default(30)->after('offboarding_exchange_only_sku');
            $table->unsignedSmallInteger('offboarding_download_expiry_days')->default(5)->after('offboarding_retention_days');
            $table->unsignedSmallInteger('offboarding_manager_grace_days')->default(3)->after('offboarding_download_expiry_days');
            $table->string('offboarding_it_escalation_email', 200)->nullable()->after('offboarding_manager_grace_days');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'avepoint_enabled',
                'avepoint_base_url',
                'avepoint_tenant_id',
                'avepoint_client_id',
                'avepoint_client_secret',
                'avepoint_region',
                'avepoint_export_endpoint',
                'avepoint_download_endpoint',
                'azure_blob_enabled',
                'azure_blob_account',
                'azure_blob_container',
                'azure_blob_key',
                'azure_blob_endpoint_suffix',
                'offboarding_enabled',
                'offboarding_group_id',
                'offboarding_exchange_only_sku',
                'offboarding_retention_days',
                'offboarding_download_expiry_days',
                'offboarding_manager_grace_days',
                'offboarding_it_escalation_email',
            ]);
        });
    }
};
