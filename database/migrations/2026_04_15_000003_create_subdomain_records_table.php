<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('domain');
            $table->string('fqdn');
            $table->string('issuer', 30)->default('letsencrypt');
            $table->string('status', 20)->default('pending');
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('csr')->nullable();
            $table->text('acme_account_key')->nullable();
            $table->string('challenge_type', 10)->default('dns01');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('last_renewed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('dns_accounts')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('subdomain_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('domain');
            $table->string('subdomain');
            $table->string('fqdn');
            $table->string('ip_address', 45);
            $table->unsignedInteger('ttl')->default(3600);
            $table->boolean('godaddy_synced')->default(false);
            $table->unsignedBigInteger('ssl_certificate_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('dns_accounts')->cascadeOnDelete();
            $table->foreign('ssl_certificate_id')->references('id')->on('ssl_certificates')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['account_id', 'domain', 'subdomain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subdomain_records');
        Schema::dropIfExists('ssl_certificates');
    }
};
