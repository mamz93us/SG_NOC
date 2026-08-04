<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business application accounts (Salesforce, Oracle, …).
 *
 * NOC does not create these accounts — they live in systems we do not control.
 * What NOC does is: ask the manager whether the new starter needs one, put the
 * employee in the matching Azure security group, and email whoever administers
 * that system with the details they need to create it.
 *
 * Two tables:
 *   business_apps          — the catalogue + per-app config (who to email, which
 *                            security group). Editable in Admin → Business Apps.
 *   employee_app_accounts  — which employee needs/has which app, so the profile
 *                            can show it and nothing is requested twice.
 *
 * Deliberately data-driven rather than two hardcoded apps: adding a third system
 * later is a row, not a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_apps', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();          // salesforce | oracle | …
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // Where the "please create this account" email goes. Comma-separated
            // so a team alias plus an individual both work.
            $table->string('request_emails', 500)->nullable();
            // Azure security group the employee joins when this app is requested.
            $table->unsignedBigInteger('identity_group_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('identity_group_id')->references('id')->on('identity_groups')->nullOnDelete();
        });

        Schema::create('employee_app_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_app_id')->constrained()->cascadeOnDelete();
            // Which onboarding request asked for it, for the audit trail.
            $table->unsignedBigInteger('workflow_id')->nullable();
            // requested = email sent, waiting on the app admin
            // active    = account confirmed created
            // revoked   = removed at offboarding
            $table->string('status', 20)->default('requested');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('account_identifier', 191)->nullable(); // username in that system, once known
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'business_app_id']);
            $table->index('status');
        });

        $now = now();
        DB::table('business_apps')->insert([
            [
                'key' => 'salesforce',
                'name' => 'Salesforce',
                'description' => 'CRM access for sales and service staff.',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'oracle',
                'name' => 'Oracle',
                'description' => 'Oracle ERP / business applications access.',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_app_accounts');
        Schema::dropIfExists('business_apps');
    }
};
