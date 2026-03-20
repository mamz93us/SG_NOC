<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
            'email_verified_at'      => 'datetime',
            'password'               => 'hashed',
            'two_factor_secret'      => 'encrypted',
            'two_factor_enabled'     => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'dark_mode'               => 'boolean',
        ];
    }

    // ── Two-Factor Authentication helpers ────────────────────

    /**
     * Check if the user has fully enabled two-factor authentication.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Generate a new TOTP secret and persist it (unconfirmed).
     */
    public function generateTwoFactorSecret(): string
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->update([
            'two_factor_secret'       => $secret,
            'two_factor_enabled'      => false,
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

    public static function roleLabel(string $role): string
    {
        return match($role) {
            'super_admin' => 'Super Admin',
            'admin'       => 'Admin',
            'viewer'      => 'Viewer',
            default       => ucfirst($role),
        };
    }
}
