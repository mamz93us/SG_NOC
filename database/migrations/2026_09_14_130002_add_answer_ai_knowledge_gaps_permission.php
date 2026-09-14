<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `answer-ai-knowledge-gaps`: answer the questions the AI Assistant could not,
 * at AI Assistant ▸ Knowledge gaps, which publishes the answer as a knowledge
 * article. Its own slug rather than manage-ai-assistant, so HR can answer
 * policy questions without the assistant's settings, instructions and PDF
 * imports.
 *
 * Also declared in RolePermission::allPermissions() — seeding role_permissions
 * alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin', 'hr'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'permission' => 'answer-ai-knowledge-gaps',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'answer-ai-knowledge-gaps')->delete();
    }
};
