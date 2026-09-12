<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes roles from hardcoded strings to rows.
 *
 * `users.role` and `role_permissions.role` keep holding the SLUG, so every
 * existing call site (`$user->role === 'super_admin'`, `where('role', ...)`,
 * the 251 permission gates) keeps working untouched — this table describes
 * roles, it does not re-key them. That is deliberate: a foreign key on
 * users.role would have meant rewriting ~40 queries and two portals for no
 * behavioural gain.
 *
 * The six roles that were hardcoded in User::roleLabel() are seeded here with
 * exactly the surfaces they already reach, so this migration is a no-op for
 * every existing login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();

            // Which app surfaces this role may reach, and which one it lands on
            // after sign-in. Replaces User::usesPortal() / homeRoute(), which
            // hardcoded the browser_user|hr|marketing list.
            $table->json('surfaces')->nullable();
            $table->string('landing', 40)->default('noc_admin');

            // is_super = implicit grant of every permission (was: slug check
            // against 'super_admin' in five places).
            $table->boolean('is_super')->default(false);
            // is_system = shipped with the app: slug is immutable and the row
            // cannot be deleted, because code and migrations reference the slug.
            $table->boolean('is_system')->default(false);

            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index('sort_order');
        });

        $now = now();

        // Seeded to match the behaviour that was hardcoded before this table:
        //   homeRoute(): marketing -> marketing portal; browser_user|hr ->
        //   portal.index; everyone else -> admin.dashboard.
        // HR additionally reaches the hr subdomain, which was (and still is)
        // gated by the `manage-hr-portal` permission rather than the role.
        $roles = [
            [
                'slug' => 'super_admin',
                'name' => 'Super Admin',
                'description' => 'Unrestricted access. Implicitly holds every permission, including future ones.',
                'surfaces' => ['noc_admin', 'noc_portal', 'hr_portal', 'marketing_portal', 'browser_portal'],
                'landing' => 'noc_admin',
                'is_super' => true,
                'is_system' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'admin',
                'name' => 'Admin',
                'description' => 'Day-to-day NOC administration. Excludes user management, credentials and integration secrets.',
                'surfaces' => ['noc_admin', 'noc_portal', 'browser_portal'],
                'landing' => 'noc_admin',
                'is_super' => false,
                'is_system' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'hr',
                'name' => 'HR',
                'description' => 'HR workspace: onboarding, offboarding and employee data requests.',
                'surfaces' => ['noc_portal', 'hr_portal', 'browser_portal'],
                'landing' => 'noc_portal',
                'is_super' => false,
                'is_system' => true,
                'sort_order' => 30,
            ],
            [
                'slug' => 'viewer',
                'name' => 'Viewer',
                'description' => 'Read-only access to the NOC.',
                'surfaces' => ['noc_admin'],
                'landing' => 'noc_admin',
                'is_super' => false,
                'is_system' => true,
                'sort_order' => 40,
            ],
            [
                'slug' => 'browser_user',
                'name' => 'Browser User',
                'description' => 'Remote browser sessions only. The default role for a new SSO sign-in.',
                'surfaces' => ['noc_portal', 'browser_portal'],
                'landing' => 'noc_portal',
                'is_super' => false,
                'is_system' => true,
                'sort_order' => 50,
            ],
            [
                'slug' => 'marketing',
                'name' => 'Marketing',
                'description' => 'Email marketing portal and training courses.',
                'surfaces' => ['noc_portal', 'marketing_portal'],
                'landing' => 'marketing_portal',
                'is_super' => false,
                'is_system' => true,
                'sort_order' => 60,
            ],
        ];

        $rows = [];
        foreach ($roles as $role) {
            $rows[] = [
                ...$role,
                'surfaces' => json_encode($role['surfaces']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('roles')->insert($rows);

        // Any role slug already sitting on a user but not in the list above
        // (hand-inserted over the years) becomes a real row rather than a
        // silently broken reference.
        $orphans = DB::table('users')
            ->select('role')
            ->whereNotNull('role')
            ->whereNotIn('role', array_column($roles, 'slug'))
            ->distinct()
            ->pluck('role');

        foreach ($orphans as $slug) {
            if ($slug === '') {
                continue;
            }

            DB::table('roles')->insert([
                'slug' => $slug,
                'name' => ucwords(str_replace('_', ' ', $slug)),
                'description' => 'Imported from an existing user record.',
                'surfaces' => json_encode(['noc_admin']),
                'landing' => 'noc_admin',
                'is_super' => false,
                'is_system' => false,
                'sort_order' => 200,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
