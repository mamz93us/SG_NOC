<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * In-request cache: user_id -> ['grant' => [...slugs], 'deny' => [...slugs]].
     *
     * Overrides layer ON TOP of the role: effective = role ∪ grant − deny.
     * (Before 2026-09, any row put the user in an allow-list mode that ignored
     * their role entirely. The migration that introduced `deny` semantics wrote
     * explicit deny rows for every role permission such a user did NOT hold, so
     * their effective set did not change on deploy.)
     */
    private static array $overrideCache = [];

    protected $fillable = [
        'name',
        'email',
        'whatsapp_number',
        'password',
        'role',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_confirmed_at',
        'dark_mode',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'dark_mode' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Store WhatsApp numbers as bare digits.
     *
     * People type them every way there is — +20 100 123 4567, (010) 123-4567.
     * The Cloud API accepts only digits, so normalise once on write rather
     * than at each of the send sites.
     */
    public function setWhatsappNumberAttribute(?string $value): void
    {
        $this->attributes['whatsapp_number'] = blank($value)
            ? null
            : (preg_replace('/\D+/', '', $value) ?: null);
    }

    // ── Two-Factor Authentication helpers ────────────────────

    /**
     * Check if the user has fully enabled two-factor authentication.
     */
    public function hasTwoFactorEnabled(): bool
    {
        try {
            return (bool) $this->two_factor_enabled && $this->two_factor_confirmed_at !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a new TOTP secret and persist it (unconfirmed).
     */
    public function generateTwoFactorSecret(): string
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ]);

        return $secret;
    }

    // ── Role helpers ───────────────────────────────────────────

    /**
     * The role row behind `users.role`.
     *
     * The column holds the slug, not an id — see the create_roles_table
     * migration. Null for a user whose slug has no row (shouldn't happen; the
     * migration imports strays), in which case every helper below degrades to
     * "no surfaces, no permissions".
     */
    public function roleModel(): ?Role
    {
        return Role::findBySlug($this->role);
    }

    public function isSuperAdmin(): bool
    {
        // Read the flag from the role row, falling back to the historic slug so
        // this still answers correctly before the roles table exists.
        return $this->roleModel()?->is_super ?? ($this->role === 'super_admin');
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin']);
    }

    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    public function isBrowserUser(): bool
    {
        return $this->role === 'browser_user';
    }

    /**
     * Whether this user's role reaches nothing but a remote browser session.
     *
     * The app's 2FA bypass hangs on this. It used to be `isBrowserUser()`, a
     * check against one slug — so a second browser-only role would have been
     * pushed into 2FA enrolment for a session that can only open a web browser.
     * See Role::onlyBrowserAccess() for why the derivation is narrow.
     */
    public function onlyBrowserAccess(): bool
    {
        return (bool) $this->roleModel()?->onlyBrowserAccess();
    }

    public function isHr(): bool
    {
        return $this->role === 'hr';
    }

    public function isMarketing(): bool
    {
        return $this->role === 'marketing';
    }

    /**
     * Whether this user may reach an app surface at all (see Role::SURFACES).
     */
    public function hasSurface(string $surface): bool
    {
        return (bool) $this->roleModel()?->hasSurface($surface);
    }

    /**
     * Portal-only users never see the admin chrome.
     *
     * Now driven by the role's surfaces rather than a hardcoded
     * browser_user|hr|marketing list, so a custom role gets the same treatment.
     * A user whose slug has no role row is treated as portal-only — the safe
     * direction, since /portal is unguarded and /admin is not.
     */
    public function usesPortal(): bool
    {
        $role = $this->roleModel();

        return $role ? $role->usesPortal() : true;
    }

    /**
     * Post-auth landing page for this user, as chosen on the role.
     *
     * Marketing's route is domain-constrained, so route() yields an absolute URL
     * on the marketing host — same as before this was data-driven.
     */
    public function homeRoute(): string
    {
        return $this->roleModel()?->landingRoute() ?? 'portal.index';
    }

    public static function roleLabel(?string $role): string
    {
        if (! $role) {
            return '—';
        }

        return Role::findBySlug($role)?->name
            ?? ucwords(str_replace('_', ' ', $role));
    }

    // ── Per-user permission overrides ───────────────────────────

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Resolve a permission for this user.
     *
     *   1. a role flagged is_super → always true
     *   2. otherwise: the role's grants, plus per-user `grant` rows,
     *      minus per-user `deny` rows. Deny always wins.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $overrides = $this->loadOverrides();

        if (in_array($slug, $overrides['deny'], true)) {
            return false;
        }

        if (in_array($slug, $overrides['grant'], true)) {
            return true;
        }

        return RolePermission::roleHas($this->role ?? '', $slug);
    }

    /**
     * Every permission slug this user effectively holds, resolved.
     *
     * Used by the user-permissions screen and the audit trail, so what is shown
     * and what is logged come from the same resolution as hasPermission().
     *
     * @return array<int,string>
     */
    public function effectivePermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return RolePermission::allSlugs();
        }

        $overrides = $this->loadOverrides();

        $effective = array_merge(
            RolePermission::forRole($this->role ?? ''),
            $overrides['grant'],
        );

        return array_values(array_unique(array_diff($effective, $overrides['deny'])));
    }

    /**
     * Per-user grant / deny rows, split by effect.
     *
     * @return array{grant: array<int,string>, deny: array<int,string>}
     */
    private function loadOverrides(): array
    {
        if (! array_key_exists($this->id, static::$overrideCache)) {
            try {
                $rows = $this->permissions()->get(['permission', 'effect']);

                static::$overrideCache[$this->id] = [
                    'grant' => $rows->where('effect', 'grant')->pluck('permission')->all(),
                    'deny' => $rows->where('effect', 'deny')->pluck('permission')->all(),
                ];
            } catch (\Throwable) {
                // Table may not exist yet (fresh deploy before migrate). Treat
                // as "no overrides" so role-only behaviour is preserved.
                static::$overrideCache[$this->id] = ['grant' => [], 'deny' => []];
            }
        }

        return static::$overrideCache[$this->id];
    }

    /**
     * Clear the in-request override cache for this user (or all users).
     */
    public static function clearOverrideCache(?int $userId = null): void
    {
        if ($userId === null) {
            static::$overrideCache = [];
        } else {
            unset(static::$overrideCache[$userId]);
        }
    }
}
