<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Recruitment\RecruitmentToolbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may use each AI feature that reads restricted data (AI ▸ AI Access).
 *
 * Not a list of its own: access is the feature's permission, resolved exactly
 * as User::hasPermission() does it — a super admin role, the permission through
 * a role, individual grants, minus individual denies — so this page, the
 * Users ▸ Permissions screen and every gate always agree. Giving access writes
 * a grant (or lifts a deny); removing it deletes the grant, or writes a deny
 * when the person holds it through their role.
 */
class AiAccess
{
    /** @return array<string, array{name: string, permission: string, description: string}> */
    public static function features(): array
    {
        return [
            'recruitment' => [
                'name' => 'Recruitment AI',
                'permission' => RecruitmentToolbox::PERMISSION,
                'description' => 'Reads Teamtailor applicants\' CVs, ranks them for a job and answers questions about them - on AI ▸ Recruitment AI, and in the Samir AI Assistant chat on the home portal.',
            ],
        ];
    }

    /**
     * Everyone who holds the feature's permission, and anyone blocked from it.
     *
     * @return Collection<int, array{user: User, through: string, blocked: bool}>
     *                                                                            through: super_admin | grant | role
     */
    public function holders(string $feature): Collection
    {
        $permission = self::features()[$feature]['permission'];

        $superRoles = ['super_admin'];
        try {
            $superRoles = array_values(array_unique(array_merge($superRoles, Role::where('is_super', true)->pluck('slug')->all())));
        } catch (\Throwable) {
            // No roles table yet: the historic slug is the super admin.
        }

        $roles = RolePermission::where('permission', $permission)->pluck('role')->all();
        $grants = UserPermission::where('permission', $permission)->where('effect', 'grant')->pluck('user_id')->all();
        $denies = UserPermission::where('permission', $permission)->where('effect', 'deny')->pluck('user_id')->all();

        return User::query()
            ->where(fn ($query) => $query->whereIn('role', array_merge($superRoles, $roles))->orWhereIn('id', array_merge($grants, $denies)))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($roles, $grants, $denies) {
                $super = $user->isSuperAdmin();

                return [
                    'user' => $user,
                    'through' => match (true) {
                        $super => 'super_admin',
                        in_array($user->id, $grants) => 'grant',
                        in_array($user->role, $roles, true) => 'role',
                        default => 'none',
                    },
                    'blocked' => ! $super && in_array($user->id, $denies),
                ];
            })
            ->filter(fn (array $row) => $row['through'] !== 'none' || $row['blocked'])
            ->values();
    }

    /** Gives the person the feature: lifts any deny, and grants unless their role already does. */
    public function grant(User $user, string $feature, ?User $actor): void
    {
        $this->change($user, $feature, $actor, 'ai_access_granted', function (string $permission) use ($user) {
            UserPermission::where('user_id', $user->id)->where('permission', $permission)->delete();

            if (! RolePermission::roleHas($user->role ?? '', $permission)) {
                UserPermission::create(['user_id' => $user->id, 'permission' => $permission, 'effect' => 'grant']);
            }
        });
    }

    /** Takes the feature away: removes a grant, and denies it when the role gives it. */
    public function revoke(User $user, string $feature, ?User $actor): void
    {
        $this->change($user, $feature, $actor, 'ai_access_revoked', function (string $permission) use ($user) {
            UserPermission::where('user_id', $user->id)->where('permission', $permission)->delete();

            if (RolePermission::roleHas($user->role ?? '', $permission)) {
                UserPermission::create(['user_id' => $user->id, 'permission' => $permission, 'effect' => 'deny']);
            }
        });
    }

    private function change(User $user, string $feature, ?User $actor, string $action, callable $write): void
    {
        $definition = self::features()[$feature];
        $permission = $definition['permission'];
        $before = $user->hasPermission($permission);

        DB::transaction(fn () => $write($permission));
        User::clearOverrideCache($user->id);

        $after = $user->fresh()->hasPermission($permission);

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'model_label' => $user->name,
            'action' => $action,
            'changes' => [
                'feature' => $definition['name'],
                'permission' => $permission,
                'role' => $user->role,
                'access' => ['old' => $before, 'new' => $after],
            ],
            'user_id' => $actor?->id,
        ]);
    }
}
