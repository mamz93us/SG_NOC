<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document archive portal — the replacement for ArcMate 7.2.
 *
 * One `archives` row per ArcMate "project" (and later per archive created here).
 * ArcMate's own shape is kept deliberately close so the read-only mirror is a
 * straight copy rather than an interpretation:
 *
 *   tblDocuments  -> archive_documents      (+ archive_document_values for the
 *                                            S1/S2/D1/C1 index columns, whose
 *                                            captions live in arcDesign.xml)
 *   tblFiles      -> archive_files          (one row per stored file)
 *   arcFullText   -> archive_file_texts     (searchable text, per page)
 *
 * Two things are NOT copied from ArcMate: its users (Entra + per-archive
 * membership replaces them) and its file encryption (only the throwaway "Test"
 * project used it — see `readable`).
 *
 * `archive_files.archive_id` is denormalised on purpose: the sync upserts by
 * (archive, ArcMate file id) and the transfer worker asks for "files of archive
 * X still on the ArcMate share", both of which would otherwise need a join
 * through archive_documents on every batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One ArcMate server: its read-only SQL login and where its file share
        // is mounted. Password is encrypted by the model, like BiotimeSource.
        Schema::create('archive_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('ArcMate');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(1433);
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('trust_server_certificate')->default(true);
            // Where the read-only cifs mount lives on this host, and what
            // ArcMate's own tblMedia.arcPath values start with on the Windows
            // side, so a stored path can be translated to the mount.
            $table->string('mount_path')->nullable();
            $table->string('arcmate_path_prefix')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->default(false);
            $table->text('last_test_error')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('last_sync_rows')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_source_id')->nullable()->constrained('archive_sources')->nullOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('description', 500)->nullable();

            // Which ArcMate project this mirrors: the folder on the share and
            // the SQL database behind it. Null for an archive created here.
            $table->string('arcmate_folder')->nullable();
            $table->string('arcmate_database')->nullable();

            // mirror     = fed by ArcMate, read-only here (the starting state)
            // native     = this portal owns it; new documents are filed here
            // read_only  = history, closed for good
            $table->string('mode', 20)->default('mirror');

            // False for an ArcMate project with <Encrypt>1</Encrypt>: its files
            // are ciphertext on disk and only ArcMate can decrypt them, so the
            // sync, the transfer and AI all skip it and say why.
            $table->boolean('readable')->default(true);
            $table->string('unreadable_reason', 255)->nullable();

            // AI is off until someone turns it on per archive — these hold
            // financial and HR scans, so it is never on by default.
            $table->boolean('ai_chat')->default(false);
            $table->boolean('ai_reading')->default(false);
            $table->boolean('ai_extract')->default(false);

            // Sync watermarks. ArcMate has no primary keys, only ascending
            // arcId values, so every read is "arcId greater than what I have".
            $table->unsignedBigInteger('last_doc_arc_id')->default(0);
            $table->unsignedBigInteger('last_file_arc_id')->default(0);
            $table->unsignedBigInteger('last_doc_track_arc_id')->default(0);
            $table->unsignedBigInteger('last_file_track_arc_id')->default(0);
            $table->unsignedBigInteger('last_deleted_arc_id')->default(0);
            $table->timestamp('backfill_done_at')->nullable();

            $table->unsignedBigInteger('document_count')->default(0);
            $table->unsignedBigInteger('file_count')->default(0);
            $table->unsignedBigInteger('byte_total')->default(0);
            $table->timestamp('counts_updated_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mode', 'readable']);
        });

        // The searchable fields of one archive. For a mirrored archive these
        // come from arcDesign.xml, where `DBName` (S1, S2, D1, C1 …) is the
        // column the value sits in on ArcMate's tblDocuments.
        Schema::create('archive_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archives')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label');
            $table->string('label_ar')->nullable();
            // text | number | date | list
            $table->string('type', 20)->default('text');
            $table->boolean('required')->default(false);
            // `unique` is reserved on the Blueprint, hence is_unique. Drives the
            // duplicate warning when filing (e.g. the same invoice number).
            $table->boolean('is_unique')->default(false);
            $table->boolean('searchable')->default(true);
            $table->json('options')->nullable();
            $table->string('ai_hint', 500)->nullable();
            $table->string('arcmate_column', 20)->nullable();
            // ArcMate's own type number, kept so an unfamiliar one can be
            // recognised later instead of silently reading as text.
            $table->unsignedSmallInteger('arcmate_type')->nullable();
            $table->unsignedSmallInteger('max_length')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['archive_id', 'key']);
            $table->index(['archive_id', 'arcmate_column']);
        });

        // Who may do what in one archive. Entra decides who you are; this
        // decides what you reach. Checked on every request and every AI tool.
        Schema::create('archive_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestamps();

            $table->unique(['archive_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('archive_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archives')->cascadeOnDelete();
            $table->unsignedBigInteger('arcmate_id')->nullable();
            $table->string('status', 20)->default('active');

            // Set when ArcMate later disagrees with a value a person or an
            // approved AI proposal put here. The sync never silently overwrites
            // such a value; it flags the document instead.
            $table->boolean('needs_review')->default(false);
            $table->string('review_note', 500)->nullable();

            // Wall clock, exactly as ArcMate holds it (Saudi local time), never
            // converted — the same rule as attendance punch times.
            $table->dateTime('captured_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by_name', 100)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['archive_id', 'arcmate_id']);
            $table->index(['archive_id', 'captured_at']);
            $table->index(['archive_id', 'status']);
            $table->index('needs_review');
        });

        Schema::create('archive_document_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_document_id')->constrained('archive_documents')->cascadeOnDelete();
            $table->foreignId('archive_field_id')->constrained('archive_fields')->cascadeOnDelete();
            // The text form is always filled (it is what people search on);
            // date and number are filled as well when the field has that type,
            // so a range search does not have to parse strings.
            $table->string('value_text', 250)->nullable();
            $table->date('value_date')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            // arcmate = copied from ArcMate; person = typed here;
            // ai_approved = proposed by AI and approved by a person.
            $table->string('source', 20)->default('arcmate');
            $table->unsignedBigInteger('set_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['archive_document_id', 'archive_field_id'], 'archive_values_document_field_unique');
            $table->index(['archive_field_id', 'value_text'], 'archive_values_field_text_index');
            $table->index(['archive_field_id', 'value_date']);
            $table->index(['archive_field_id', 'value_number']);
        });

        Schema::create('archive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archives')->cascadeOnDelete();
            $table->foreignId('archive_document_id')->constrained('archive_documents')->cascadeOnDelete();
            $table->unsignedBigInteger('arcmate_id')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // ArcMate names a stored file by its capture timestamp, so the
            // original name it was scanned or attached under is the only human
            // label there is.
            $table->string('original_name')->nullable();
            // Which disk `path` belongs to: `arcmate` (the read-only mount) or
            // `azure_archive` once the transfer worker has verified a copy.
            $table->string('disk', 30)->default('arcmate');
            $table->string('path', 500);
            // Kept after the transfer so the origin of every file stays on the
            // record, and a re-verify can still find the original.
            $table->string('arcmate_path', 500)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('arcmate_crc', 40)->nullable();
            $table->timestamps();

            $table->unique(['archive_id', 'arcmate_id']);
            $table->index(['archive_document_id', 'position']);
            $table->index(['archive_id', 'disk']);
        });

        // One row per page of readable text: ArcMate's own OCR where it saved
        // any, the PDF's embedded text where it has some, or AI's reading.
        // Separate table because the text dwarfs the rest of the index and is
        // wanted only when searching or answering.
        Schema::create('archive_file_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_file_id')->constrained('archive_files')->cascadeOnDelete();
            $table->unsignedSmallInteger('page')->default(1);
            $table->longText('text')->nullable();
            // arcmate_ocr | pdf_text | ai
            $table->string('source', 20)->default('ai');
            $table->timestamp('read_at')->nullable();

            $table->unique(['archive_file_id', 'page']);
        });

        // MySQL-only: the words index behind the "anywhere" search box. Guarded
        // so a SQLite test database can still create the table.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE archive_file_texts ADD FULLTEXT archive_file_texts_text_fulltext (text)');
        }

        // Who opened which document, and when. Financial and HR scans, so this
        // is its own table rather than a line in activity_logs: it is written on
        // every view and read as a report.
        Schema::create('archive_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('archive_id')->nullable();
            $table->unsignedBigInteger('archive_document_id')->nullable();
            $table->unsignedBigInteger('archive_file_id')->nullable();
            // view | download | ask
            $table->string('action', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['archive_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('archive_document_id');
        });

        // Work a page asks for but must never do inline — re-matching, sample
        // verification, retries. `archive:work` drains this every minute, the
        // same arrangement as AttendanceTask + attendance:work, which exists
        // because doing it in the request held a PHP-FPM worker to a 504.
        Schema::create('archive_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->json('params')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_tasks');
        Schema::dropIfExists('archive_access_logs');
        Schema::dropIfExists('archive_file_texts');
        Schema::dropIfExists('archive_files');
        Schema::dropIfExists('archive_document_values');
        Schema::dropIfExists('archive_documents');
        Schema::dropIfExists('archive_members');
        Schema::dropIfExists('archive_fields');
        Schema::dropIfExists('archives');
        Schema::dropIfExists('archive_sources');
    }
};
