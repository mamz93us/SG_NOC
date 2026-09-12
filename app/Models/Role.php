<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A role, as a row rather than a hardcoded string.
 *
 * `users.role` holds this row's SLUG, not its id — see the create_roles_table
 * migration for why. Everything that used to be a `match` on the slug lives
 * here instead: the display label, whether the role is a superuser, and which
 * app surfaces it may reach.
 */
class Role extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'surfaces',
        'landing',
        'is_super',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'surfaces' => 'array',
        'is_super' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** In-request cache: slug -> ?Role. Roles are read on every gate check. */
    private static ?Collection $allCache = null;

    // ── Surfaces ────────────────────────────────────────────────

    /**
     * The app surfaces a role can be given. Each is a distinct sign-in
     * destination with its own host isolation and its own chrome.
     *
     * Keys are stored in `roles.surfaces` / `roles.landing`.
     */
    public const SURFACES = [
        'noc_admin' => 'NOC Admin',
        'noc_portal' => 'NOC Portal',
        'hr_portal' => 'HR Portal',
        'marketing_portal' => 'Marketing Portal',
        'browser_portal' => 'Remote Browser',
    ];

    /** One-line description of each surface, for the role form. */
    public const SURFACE_HINTS = [
        'noc_admin' => 'The full admin area at /admin — NOC dashboards, ITAM, network, identity.',
        'noc_portal' => 'The internal portal hub at /portal — request forms and self-service.',
        'hr_portal' => 'The isolated HR workspace on the hr subdomain.',
        'marketing_portal' => 'The isolated email-marketing portal on the em subdomain.',
        'browser_portal' => 'Remote browser sessions (per-office VPN egress) only.',
    ];

    /**
     * Where each surface lands after sign-in.
     *
     * Marketing resolves to an absolute URL because its route is
     * domain-constrained; the rest are ordinary named routes.
     */
    public const SURFACE_ROUTES = [
        'noc_admin' => 'admin.dashboard',
        'noc_portal' => 'portal.index',
        'hr_portal' => 'portal.hr.index',
        'marketing_portal' => 'portal.marketing.dashboard',
        'browser_portal' => 'portal.index',
    ];

    public function surfaceList(): array
    {
        return array_values(array_intersect(
            (array) ($this->surfaces ?? []),
            array_keys(self::SURFACES)
        ));
    }

    public function hasSurface(string $surface): bool
    {
        return in_array($surface, $this->surfaceList(), true);
    }

    /**
     * The route name this role lands on. Falls back to the first granted
     * surface, then to the portal hub — never to /admin, because bouncing a
     * portal-only user into /admin is the redirect loop this replaces.
     */
    public function landingRoute(): string
    {
        $surface = $this->landing;

        if (! $surface || ! $this->hasSurface($surface)) {
            $surface = $this->surfaceList()[0] ?? 'noc_portal';
        }

        return self::SURFACE_ROUTES[$surface] ?? 'portal.index';
    }

    /**
     * Portal-only roles never see the admin chrome. This is the replacement for
     * User::usesPortal(), which hardcoded browser_user|hr|marketing.
     */
    public function usesPortal(): bool
    {
        return ! $this->hasSurface('noc_admin');
    }

    /**
     * Whether this role reaches nothing but a remote browser session.
     *
     * This is the condition the app's 2FA bypass hangs on — previously the
     * literal check `role === 'browser_user'` in MicrosoftController and
     * RequireTwoFactor. Deriving it means a second browser-only role (per-office,
     * say) behaves the same instead of being stuck at 2FA enrolment for a session
     * that can only open a web browser.
     *
     * Deliberately narrow: the role must reach browser_portal and nothing beyond
     * the portal hub, which is only the launcher for it. Any role that can also
     * reach the admin area, the HR workspace or the marketing portal is holding
     * real data behind it and keeps the second factor.
     */
    public function onlyBrowserAccess(): bool
    {
        $surfaces = $this->surfaceList();

        if (! in_array('browser_portal', $surfaces, true)) {
            return false;
        }

        return array_diff($surfaces, ['browser_portal', 'noc_portal']) === [];
    }

    // ── Permissions ─────────────────────────────────────────────

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role', 'slug');
    }

    /** @return array<int,string> */
    public function permissionSlugs(): array
    {
        return RolePermission::forRole($this->slug);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    // ── Lookup (cached per request) ─────────────────────────────

    /**
     * Every role, ordered for display. Cached for the request because the gate
     * resolves a role on each permission check.
     *
     * Returns an empty collection if the table isn't there yet (fresh checkout
     * before migrate), so boot-time gate registration can't fatal.
     */
    public static function cached(): Collection
    {
        if (static::$allCache === null) {
            try {
                static::$allCache = static::orderBy('sort_order')->orderBy('name')->get();
            } catch (\Throwable) {
                static::$allCache = new Collection;
            }
        }

        return static::$allCache;
    }

    public static function findBySlug(?string $slug): ?self
    {
        if (! $slug) {
            return null;
        }

        return static::cached()->firstWhere('slug', $slug);
    }

    /** @return array<string,string> slug => name, for select menus. */
    public static function options(): array
    {
        return static::cached()->pluck('name', 'slug')->all();
    }

    /** Assignable slugs, for `in:` validation rules. */
    public static function slugs(): array
    {
        return static::cached()->pluck('slug')->all();
    }

    public static function clearCache(): void
    {
        static::$allCache = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    public function scopeAssignable(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    /**
     * A system role's slug is referenced by code and by migrations, so it can
     * be renamed but never re-slugged or removed.
     */
    public function isLocked(): bool
    {
        return (bool) $this->is_system;
    }

    // ── Display ─────────────────────────────────────────────────

    /**
     * Bootstrap badge class for the role.
     *
     * Keyed off the historic slugs so the six shipped roles keep the colours
     * people already recognise; a custom role is coloured deterministically from
     * its slug, so it looks the same on every page without anyone picking one.
     */
    public function badgeClass(): string
    {
        return match ($this->slug) {
            'super_admin' => 'bg-danger',
            'admin' => 'bg-primary',
            'hr' => 'bg-info',
            'viewer' => 'bg-secondary',
            'browser_user' => 'bg-warning text-dark',
            'marketing' => 'bg-success',
            default => self::PALETTE[crc32($this->slug) % count(self::PALETTE)],
        };
    }

    private const PALETTE = [
        'bg-primary',
        'bg-success',
        'bg-info',
        'bg-dark',
        'bg-secondary',
    ];

    public static function badgeFor(?string $slug): string
    {
        return static::findBySlug($slug)?->badgeClass() ?? 'bg-secondary';
    }
}
