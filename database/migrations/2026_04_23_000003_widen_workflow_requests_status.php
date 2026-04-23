<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Current column is VARCHAR(20) which cannot hold 'awaiting_manager_form' (21 chars).
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
    }
};
