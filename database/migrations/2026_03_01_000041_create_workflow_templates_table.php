<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type_slug', 50)->unique();
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            $table->json('approval_chain');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed built-in templates
        $templates = [
            ['type_slug' => 'create_user',       'display_name' => 'Create User',           'description' => 'Onboard a new employee in Azure AD and UCM', 'approval_chain' => json_encode(['hr', 'it_manager']),          'is_system' => true],
            ['type_slug' => 'delete_user',       'display_name' => 'Delete User',           'description' => 'Offboard and disable a user account',          'approval_chain' => json_encode(['it_manager', 'super_admin']), 'is_system' => true],
            ['type_slug' => 'license_change',    'display_name' => 'License Change',        'description' => 'Assign or remove a Microsoft 365 license',     'approval_chain' => json_encode(['it_manager']),               'is_system' => true],
            ['type_slug' => 'asset_assign',      'display_name' => 'Asset Assignment',      'description' => 'Assign a device asset to an employee',          'approval_chain' => json_encode(['manager']),                  'is_system' => true],
            ['type_slug' => 'asset_return',      'display_name' => 'Asset Return',          'description' => 'Return a device asset from an employee',        'approval_chain' => json_encode(['manager']),                  'is_system' => true],
            ['type_slug' => 'extension_create',  'display_name' => 'Extension Create',      'description' => 'Create a new UCM VoIP extension',              'approval_chain' => json_encode(['it_manager']),               'is_system' => true],
            ['type_slug' => 'extension_delete',  'display_name' => 'Extension Delete',      'description' => 'Delete a UCM VoIP extension',                  'approval_chain' => json_encode(['it_manager']),               'is_system' => true],
            ['type_slug' => 'license_purchase',  'display_name' => 'License Purchase',      'description' => 'Request procurement to purchase new licenses',  'approval_chain' => json_encode(['it_manager', 'super_admin']), 'is_system' => true],
            ['type_slug' => 'other',             'display_name' => 'Other Request',         'description' => 'General IT request requiring approval',         'approval_chain' => json_encode(['it_manager']),               'is_system' => true],
        ];

        foreach ($templates as $t) {
            DB::table('workflow_templates')->insert(array_merge($t, [
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
