<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert ENUM to VARCHAR(50) for full flexibility with workflow templates
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN type VARCHAR(50) NOT NULL");
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN type ENUM('create_user','delete_user','license_change','asset_assign','asset_return','extension_create','extension_delete','other') NOT NULL");
        DB::statement("ALTER TABLE workflow_requests MODIFY COLUMN status ENUM('draft','pending','approved','rejected','executing','completed','failed') NOT NULL DEFAULT 'draft'");
    }
};
