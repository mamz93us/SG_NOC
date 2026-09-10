<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each ZKTeco BioTime SQL Server database the NOC reads punches from.
 *
 * There is more than one BioTime install, so a source is a row rather than a
 * set of .env keys or `settings` columns — `settings` is already at InnoDB's
 * row-size limit. The password is encrypted by the model.
 *
 * `last_id` is the iclock_transaction.id watermark, deliberately not a punch
 * time: a device that was offline uploads old punches days later with NEW ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biotime_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(1433);
            $table->string('database', 128);
            $table->string('username', 128);
            $table->text('password')->nullable();
            $table->boolean('trust_server_certificate')->default(true);
            $table->boolean('enabled')->default(true);

            // Settles an emp_code that matches two employees (the SSS Egypt /
            // SamirGroup oracle_emp_no series collide) when no area says which.
            $table->unsignedInteger('default_branch_id')->nullable()->index();
            $table->foreign('default_branch_id')->references('id')->on('branches')->nullOnDelete();

            // First sync starts here instead of at the beginning of history.
            $table->date('import_from')->nullable();

            $table->unsignedBigInteger('last_id')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedInteger('last_sync_rows')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_result', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biotime_sources');
    }
};
