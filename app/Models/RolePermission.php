<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $fillable = ['role', 'permission'];

    /** In-request cache: role -> [permission, ...] */
    private static array $cache = [];

    /**
     * All available permissions grouped by category.
     * Keys are permission slugs, values are display labels.
     */
    public static function allPermissions(): array
    {
        return [
            'Branches' => [
                'view-branches'   => 'View Branches',
                'manage-branches' => 'Create / Edit / Delete Branches',
            ],
            'Contacts' => [
                'view-contacts'   => 'View Contacts',
                'manage-contacts' => 'Create / Edit / Delete Contacts',
                'export-contacts' => 'Export Contacts (CSV)',
            ],
            'Logs' => [
                'view-activity-logs' => 'View Activity Logs',
                'view-phone-logs'    => 'View Phone XML Logs',
                'sync-phone-logs'    => 'Sync Phone XML Logs',
            ],
            'PBX' => [
                'view-extensions'   => 'View Extensions',
                'manage-extensions' => 'Create / Edit / Delete Extensions',
                'view-trunks'       => 'View VoIP Trunks',
            ],
            'Network' => [
                'view-network'            => 'View Network (Switches, Clients, Events)',
                'manage-network-settings' => 'Manage Meraki Network Settings',
                'manage-vpn-settings'     => 'Manage VPN Settings', // Added based on instruction context
                'view-network-events'     => 'View Network Change Events',
            ],
            'Assets' => [
                'view-assets'   => 'View Device Inventory',
                'manage-assets' => 'Create / Edit / Delete Devices',
            ],
            'Credentials' => [
                'view-credentials'   => 'View Credentials (masked)',
                'manage-credentials' => 'Create / Edit / Delete / Reveal Credentials',
            ],
            'Printers' => [
                'view-printers'   => 'View Printer Inventory',
                'manage-printers' => 'Create / Edit / Delete Printers',
            ],
            'Identity' => [
                'view-identity'            => 'View Identity (Users, Licenses, Groups)',
                'manage-identity'          => 'Manage Identity (Reset PW, Toggle, Assign)',
                'manage-identity-settings' => 'Manage Microsoft Graph API Settings',
            ],
            'Administration' => [
                'manage-settings'    => 'Access & Edit Settings',
                'manage-users'       => 'Manage Users',
                'manage-permissions' => 'Manage Role Permissions',
            ],
            'Workflows' => [
                'view-workflows'    => 'View Workflow Requests',
                'manage-workflows'  => 'Create / Cancel Workflow Requests',
                'approve-workflows' => 'Approve / Reject Workflow Steps',
            ],
            'Employees' => [
                'view-employees'   => 'View Employee Directory',
                'manage-employees' => 'Create / Edit Employees & Assign Assets',
            ],
            'NOC' => [
                'view-noc'        => 'View NOC Dashboard & Events',
                'manage-noc'      => 'Acknowledge / Resolve NOC Events',
                'view-incidents'  => 'View Incidents',
                'manage-incidents'=> 'Create / Edit / Close Incidents',
            ],
            'Platform' => [
                'manage-workflow-templates' => 'Edit Workflow Types & Approval Chains',
                'view-email-logs'           => 'View Email Send Log',
                'manage-notification-rules' => 'Manage Notification Routing Rules',
                'manage-license-monitors'   => 'Manage License Inventory Monitors',
                'manage-allowed-domains'    => 'Manage Allowed Domains',
            ],
        ];
    }

    /**
     * Flat list of all permission slugs.
     */
    public static function allSlugs(): array
    {
        return collect(static::allPermissions())
            ->flatMap(fn($perms) => array_keys($perms))
            ->all();
    }

    /**
     * Default permissions per role.
     */
    public static function defaultPermissions(): array
    {
        $all         = static::allSlugs();
        $adminPerms  = array_values(array_diff($all, [
            'manage-users', 'manage-permissions',
            'manage-credentials', 'manage-identity-settings',
        ]));
        $viewerPerms = [
            'view-branches', 'view-contacts',
            'view-activity-logs', 'view-phone-logs',
            'view-extensions', 'view-trunks',
            'view-network', 'view-assets', 'view-printers',
            'view-workflows', 'view-employees', 'view-noc',
        ];

        return [
            'super_admin' => $all,
            'admin'       => $adminPerms,
            'viewer'      => $viewerPerms,
        ];
    }

    /**
     * Get all permission slugs for a role (cached per-request).
     */
    public static function forRole(string $role): array
    {
        if (!isset(static::$cache[$role])) {
            static::$cache[$role] = static::where('role', $role)->pluck('permission')->all();
        }
        return static::$cache[$role];
    }

    /**
     * Check if a role has a specific permission.
     */
    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, static::forRole($role));
    }

    /**
     * Clear the in-request cache (call after saving).
     */
    public static function clearCache(): void
    {
        static::$cache = [];
    }
}
