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
     * In-request cache: user_id -> ?array of granted slugs.
     * null = user has no custom rows (fall back to role).
     * array = user is in custom-list mode; ONLY listed slugs are granted.
     */
    private static array $overrideCache = [];

    protected $fillable = [
        'name',
        'email',
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

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
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

    public function isHr(): bool
    {
        return $this->role === 'hr';
    }

    public function isMarketing(): bool
    {
        return $this->role === 'marketing';
    }

    /**
     * Portal-only roles never see admin chrome.
     */
    public function usesPortal(): bool
    {
        return $this->isBrowserUser() || $this->isHr() || $this->isMarketing();
    }

    /**
     * Post-auth landing page for this user. Portal-only roles (browser_user, hr)
     * go straight into the portal; everyone else hits the admin dashboard.
     */
    public function homeRoute(): string
    {
        // Marketing-only users land directly on the isolated marketing portal
        // (its own subdomain). The route name is unchanged; because the route is
        // domain-constrained, route() yields an absolute URL on the marketing host.
        if ($this->isMarketing()) {
            return 'portal.marketing.dashboard';
        }

        return $this->usesPortal() ? 'portal.index' : 'admin.dashboard';
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            'hr' => 'HR',
            'viewer' => 'Viewer',
            'browser_user' => 'Browser User',
            'marketing' => 'Marketing',
            default => ucfirst($role),
        };
    }

    // ── Per-user permission overrides ───────────────────────────

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Resolve permission for this user.
     *   1. super_admin → always true
     *   2. User has any rows in user_permissions → allow-list mode:
     *      ONLY listed slugs are granted; role is ignored.
     *   3. No rows → fall back to role default.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        $custom = $this->loadCustomGrants();
        if ($custom !== null) {
            return in_array($slug, $custom, true);
        }

        return RolePermission::roleHas($this->role ?? '', $slug);
    }

    /**
     * The custom allow-list for this user, or null when there are no rows
     * (role fallback applies).
     *
     * @return array<int,string>|null
     */
    private function loadCustomGrants(): ?array
    {
        if (! array_key_exists($this->id, static::$overrideCache)) {
            try {
                $slugs = $this->permissions()->pluck('permission')->all();
                static::$overrideCache[$this->id] = empty($slugs) ? null : $slugs;
            } catch (\Throwable) {
                // Table may not exist yet (fresh deploy before migrate). Treat
                // as "no custom rows" so role-only behaviour is preserved.
                static::$overrideCache[$this->id] = null;
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
