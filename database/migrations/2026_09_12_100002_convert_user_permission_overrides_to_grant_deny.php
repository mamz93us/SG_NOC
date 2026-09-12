<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Changes per-user overrides from allow-list to grant/deny layering.
 *
 * Before: ANY row in user_permissions put the user in allow-list mode — their
 * role was ignored entirely and only the listed slugs were granted. The
 * `effect` column existed but nothing read it.
 *
 * After: effective = role ∪ grant − deny.
 *
 * Those two rules disagree for anyone who already had rows: under the new rule
 * their role's permissions would come back. So for each such user this writes an
 * explicit `deny` row for every permission their role grants that was NOT in
 * their allow-list. Their effective set on the morning after deploy is byte-for-
 * byte what it was the night before — which matters, because these rows exist
 * specifically to take access away from someone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $now = now();

        // Users who are in the old allow-list mode.
        $userIds = DB::table('user_permissions')->distinct()->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        // role slug => granted permission slugs
        $roleGrants = DB::table('role_permissions')
            ->get(['role', 'permission'])
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('permission')->all());

        foreach ($userIds as $userId) {
            $user = DB::table('users')->where('id', $userId)->first(['id', 'role']);

            if (! $user) {
                continue;
            }

            // Everything currently on the user, whatever the unused effect said:
            // in allow-list mode every row was a grant.
            $allowList = DB::table('user_permissions')
                ->where('user_id', $userId)
                ->pluck('permission')
                ->all();

            DB::table('user_permissions')
                ->where('user_id', $userId)
                ->update(['effect' => 'grant', 'updated_at' => $now]);

            // A super_admin's rows never applied, so there is nothing to preserve.
            $isSuper = DB::table('roles')
                ->where('slug', $user->role)
                ->value('is_super');

            if ($isSuper) {
                continue;
            }

            $fromRole = $roleGrants[$user->role] ?? [];
            $toDeny = array_values(array_diff($fromRole, $allowList));

            if ($toDeny === []) {
                continue;
            }

            DB::table('user_permissions')->insert(array_map(fn ($slug) => [
                'user_id' => $userId,
                'permission' => $slug,
                'effect' => 'deny',
                'created_at' => $now,
                'updated_at' => $now,
            ], $toDeny));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_permissions')) {
            return;
        }

        // Going back to allow-list mode means the deny rows are meaningless —
        // and leaving them would grant what they were written to take away.
        DB::table('user_permissions')->where('effect', 'deny')->delete();
    }
};
