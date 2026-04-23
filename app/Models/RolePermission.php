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
                'manage-vpn-settings'     => 'Manage VPN Settings',
                'view-network-events'     => 'View Network Change Events',
                'view-dhcp-leases'        => 'View DHCP Leases',
                'view-sophos'             => 'View Sophos Firewalls',
                'manage-sophos'           => 'Manage Sophos Firewalls',
                'view-dns'                => 'View DNS Accounts & Domains',
                'manage-dns'              => 'Manage DNS Records & Settings',
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
            'HR' => [
                'submit-hr-onboarding' => 'Submit HR Onboarding Requests (portal)',
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
            'ITAM' => [
                'view-itam'           => 'View ITAM Dashboard',
                'manage-itam'         => 'Manage ITAM (Azure Sync)',
                'manage-suppliers'    => 'Manage Suppliers',
                'view-licenses'       => 'View Software Licenses',
                'manage-licenses'     => 'Create / Edit / Delete Licenses & Assign',
                'view-accessories'    => 'View Accessories',
                'manage-accessories'  => 'Create / Edit / Delete Accessories & Assign',
            ],
            'Admin Tools' => [
                'view-admin-links'   => 'View Admin Tools / Quick Links',
                'manage-admin-links' => 'Create / Edit / Delete Admin Links',
            ],
            'Documentation' => [
                'view-documentation'   => 'View Documentation',
                'manage-documentation' => 'Upload / Delete Documentation Files',
            ],
            'Print Manager' => [
                'view-print-manager'   => 'View CUPS Print Manager',
                'manage-print-manager' => 'Manage CUPS Printers & Jobs',
            ],
            'Remote Browser' => [
                'view-browser-portal'    => 'Launch & use a remote browser session',
                'share-browser-session'  => 'Generate a share link to invite another user into their own session',
                'manage-browser-portal'  => 'View / force-stop any session, view events, edit portal settings',
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
            'view-dhcp-leases', 'view-sophos', 'view-dns', 'view-admin-links',
        ];
        $hrPerms = [
            'submit-hr-onboarding',
            'view-workflows',
            'view-employees',
            'view-contacts',
            'view-browser-portal',
        ];

        return [
            'super_admin'  => $all,
            'admin'        => $adminPerms,
            'hr'           => $hrPerms,
            'viewer'       => $viewerPerms,
            'browser_user' => ['view-browser-portal'],
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
