<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment servers — Linux hosts the team deploys to over SSH.
 *
 * An admin registers each server once (host, user, uploaded .pem/.ppk) and the
 * credentials live here encrypted at rest. The Node WS proxy (telnet-proxy/)
 * is the only thing that ever sees the plaintext: it fetches it through the
 * short-lived token handshake at /internal/telnet-token/{token}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('hostname', 255)->comment('IP or DNS name — never taken from the browser');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username', 100);
            $table->string('auth_type', 16)->default('key')->comment('key | password');

            // Ciphertext, not hashes — the proxy needs the plaintext back to
            // authenticate. AES-256-CBC output runs well past a varchar.
            $table->text('private_key')->nullable();
            $table->text('key_passphrase')->nullable();
            $table->text('password')->nullable();

            $table->string('key_filename', 255)->nullable()->comment('Original upload name, shown instead of the key');
            $table->string('key_fingerprint', 255)->nullable();
            $table->string('key_format', 16)->nullable()->comment('openssh | pem | ppk | unknown');

            $table->string('working_directory', 255)->nullable()->comment('Default cd target for this server commands');
            $table->text('description')->nullable();

            // NOTE on branch_id: `branches.id` is a legacy `int unsigned`, not a
            // bigint. `foreignId()` would emit `bigint unsigned` and MySQL rejects
            // the foreign key with errno 3780 ("incompatible" columns), so the
            // column is declared explicitly and the FK added separately below.
            $table->unsignedInteger('branch_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('deploy_servers', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_servers');
    }
};
