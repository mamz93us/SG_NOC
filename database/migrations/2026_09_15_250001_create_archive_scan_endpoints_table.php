<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: the addresses and folders a scanner sends to.
 *
 * One row per destination. A copier is configured once with an address or an SFTP
 * account, and everything it sends afterwards lands in somebody's inbox without
 * anyone touching a browser — which is the whole point, because the people
 * scanning invoices all day will not upload them one at a time.
 *
 * Two kinds, and they differ only in how the bytes arrive:
 *
 *   email  — the copier mails the scan to `u-<token>@scan.archive…` (a person) or
 *            `a-<token>@scan.archive…` (an archive's shared inbox). Postfix drops
 *            it in a spool directory and archive:ingest-mail reads it.
 *   folder — the copier writes over SFTP into an SFTPGo account's home, and
 *            archive:sweep-scan-folders picks up each settled file.
 *
 * **Routing is by the RECIPIENT, never the sender.** The NOC's Postfix rewrites
 * every sender to one SES-verified identity (see deployment/smtp-relay), so the
 * From address of an arriving scan says nothing about who sent it. The token in
 * the recipient is the only thing that identifies a destination.
 *
 * The token is therefore a credential in an address people can read off a copier
 * screen: it is stored hashed, shown once when created, and long enough that
 * guessing one is not worth trying. Anyone who learns it can put documents into
 * that inbox — they cannot read anything, which is why this is acceptable at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_scan_endpoints', function (Blueprint $table) {
            $table->id();

            // email | folder
            $table->string('type', 20);

            // Where what arrives here goes. A personal destination (its owner
            // files it) or an archive's shared one (whoever may add to that
            // archive sees it). Exactly one is set.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('archive_id')->nullable()->constrained('archives')->nullOnDelete();

            // What it is for, in the words of whoever set it up: "Reception Ricoh",
            // "Finance scanner". Shown beside the address so a stale destination
            // can be recognised rather than guessed at.
            $table->string('label');

            // The token as it appears in the address, hashed. Never stored in the
            // clear: it is a write credential printed on a copier's display, and
            // the audit log must not become the one place it is readable.
            $table->string('token_hash', 64)->nullable();
            // The first few characters, so the list can show which row an address
            // belongs to without revealing the token.
            $table->string('token_hint', 12)->nullable();

            // folder only: the SFTPGo user the copier signs in as.
            $table->string('sftpgo_username', 100)->nullable();
            $table->string('home_dir', 255)->nullable();

            $table->boolean('enabled')->default(true);

            // Monitoring. A scanner that stops sending is the failure people
            // notice last — the paper still comes out of the machine.
            $table->timestamp('last_received_at')->nullable();
            $table->unsignedInteger('received_count')->default(0);
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // The lookup every arriving scan does, on its own index.
            $table->unique('token_hash');
            $table->unique('sftpgo_username');
            $table->index(['type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_scan_endpoints');
    }
};
