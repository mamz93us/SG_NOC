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
                'view-branches' => 'View Branches',
                'manage-branches' => 'Create / Edit / Delete Branches',
            ],
            'Contacts' => [
                'view-contacts' => 'View Contacts',
                'manage-contacts' => 'Create / Edit / Delete Contacts',
                'export-contacts' => 'Export Contacts (CSV)',
            ],
            'Logs' => [
                'view-activity-logs' => 'View Activity Logs',
                'view-phone-logs' => 'View Phone XML Logs',
                'sync-phone-logs' => 'Sync Phone XML Logs',
                'view-smtp-relay' => 'View SMTP Relay Log',
            ],
            'PBX' => [
                'view-extensions' => 'View Extensions',
                'manage-extensions' => 'Create / Edit / Delete Extensions',
                'view-trunks' => 'View VoIP Trunks',
                'view-phones' => 'View Desk Phones (GDMS / Zero Config)',
                'manage-phones' => 'Create / Edit / Delete Desk Phones & Push Config',
                'view-phone-firmware' => 'View Phone Firmware Library & Fleet Versions',
                'manage-phone-firmware' => 'Upload / Publish / Delete Phone Firmware',
            ],
            'Network' => [
                'view-network' => 'View Network (Switches, Clients, Events)',
                'manage-network-settings' => 'Manage Meraki Network Settings',
                'view-network-events' => 'View Network Change Events',
                'view-dhcp-leases' => 'View DHCP Leases',
                'view-sophos' => 'View Sophos Firewalls',
                'manage-sophos' => 'Manage Sophos Firewalls',
                'view-fortigate' => 'View FortiGate Firewalls',
                'manage-fortigate' => 'Manage FortiGate Firewalls (API keys, sync)',
                'view-access-points' => 'View Access Points',
                'manage-access-points' => 'Manage Access Points (import, ping, edit)',
                'view-dns' => 'View DNS Accounts & Domains',
                'manage-dns' => 'Manage DNS Records & Settings',
                'manage-radius' => 'Manage RADIUS (MAC auth, VLAN policy, NAS clients)',
                'view-branch-agents' => 'View Branch Agents',
                'manage-branch-agents' => 'Manage Branch Agents (enroll, edit, delete)',
                'view-voice-mesh' => 'View Voice Mesh Matrix',
                'manage-voice-mesh' => 'Manage Voice Mesh Nodes & Settings',
                'view-voice-quality' => 'View Voice Quality Dashboard & Reports',
            ],
            'Assets' => [
                'view-assets' => 'View Device Inventory',
                'manage-assets' => 'Create / Edit / Delete Devices',
                'manage-devices' => 'Browse / SSH / Telnet into a Device',
            ],
            'Credentials' => [
                'view-credentials' => 'View Credentials (masked)',
                'manage-credentials' => 'Create / Edit / Delete / Reveal Credentials',
            ],
            'Mail Delivery' => [
                'view-mail-delivery' => 'View SES Mail Delivery Log (every message the AWS account sent)',
            ],
            'Deployment' => [
                'view-deploy-servers' => 'View Deployment Servers & Run History',
                'run-deploy-commands' => 'Open SSH Terminal & Run Deploy Commands',
                'manage-deploy-servers' => 'Create / Edit / Delete Deployment Servers (SSH keys, commands)',
            ],
            'Device Backups' => [
                'view-backups' => 'View Device Backup Accounts & Status',
                'manage-backups' => 'Create / Edit / Rotate / Delete Backup Accounts',
            ],
            'Printers' => [
                'view-printers' => 'View Printer Inventory',
                'manage-printers' => 'Create / Edit / Delete Printers',
                'view-printer-usage' => 'View Printer Usage & Counter Reports',
                'manage-printer-alerts' => 'Manage Printer Toner Alerts & Branch Settings',
            ],
            'Wallpapers' => [
                'view-wallpapers' => 'View Managed Wallpapers + deployment links',
                'manage-wallpapers' => 'Add domains / Upload / Delete wallpapers',
            ],
            'Identity' => [
                'view-identity' => 'View Identity (Users, Licenses, Groups)',
                'manage-identity' => 'Manage Identity (Reset PW, Toggle, Assign)',
                'manage-identity-settings' => 'Manage Microsoft Graph API Settings',
            ],
            'Administration' => [
                'manage-settings' => 'Access & Edit Settings',
                'manage-users' => 'Manage Users',
                'manage-permissions' => 'Manage Role Permissions',
            ],
            'Workflows' => [
                'view-workflows' => 'View Workflow Requests',
                'manage-workflows' => 'Create / Cancel Workflow Requests',
                'approve-workflows' => 'Approve / Reject Workflow Steps',
            ],
            'Employees' => [
                'view-employees' => 'View Employee Directory',
                'manage-employees' => 'Create / Edit Employees & Assign Assets',
            ],
            'Offboarding' => [
                'view-offboarding' => 'View Offboarding Workflows',
                'manage-offboarding' => 'Manage Offboarding (force delete, cancel, upload backups)',
            ],
            'AvePoint' => [
                'view-avepoint' => 'View AvePoint Module (dashboard, users, jobs, backups)',
                'manage-avepoint' => 'Manage AvePoint Backups (request, retry, download)',
            ],
            'HR' => [
                'manage-hr-portal' => 'Access the HR Workspace (portal)',
                'submit-hr-onboarding' => 'Submit HR Onboarding Requests (portal)',
                'submit-hr-offboarding' => 'Submit HR Termination / Offboarding Requests (portal)',
                'submit-hr-employee-update' => 'Submit HR Employee Data Change Requests (portal)',
            ],
            'NOC' => [
                'view-noc' => 'View NOC Dashboard & Events',
                'manage-noc' => 'Acknowledge / Resolve NOC Events',
                'view-incidents' => 'View Incidents',
                'manage-incidents' => 'Create / Edit / Close Incidents',
                'view-syslog' => 'View Syslog Messages & Search',
                'manage-syslog' => 'Manage Syslog Alert Rules',
            ],
            'Platform' => [
                'manage-workflow-templates' => 'Edit Workflow Types & Approval Chains',
                'view-email-logs' => 'View Email Send Log',
                'view-whatsapp-logs' => 'View WhatsApp Send Log',
                'manage-notification-rules' => 'Manage Notification Routing Rules',
                'manage-license-monitors' => 'Manage License Inventory Monitors',
                'manage-allowed-domains' => 'Manage Allowed Domains',
                'view-server-status' => 'View Server Status (disk, services, DB backups)',
                'manage-server-status' => 'Run DB Backups & Service Actions',
                'view-downloads' => 'View Download Center',
                'manage-downloads' => 'Upload / Delete Download Center Files',
                'manage-roles' => 'Create / Edit / Delete Roles',
            ],
            'ITAM' => [
                'view-itam' => 'View ITAM Dashboard',
                'manage-itam' => 'Manage ITAM (Azure Sync, Transfer, Branch Stores)',
                'manage-suppliers' => 'Manage Suppliers',
                'view-licenses' => 'View Software Licenses',
                'manage-licenses' => 'Create / Edit / Delete Licenses & Assign',
                'view-accessories' => 'View Accessories',
                'manage-accessories' => 'Create / Edit / Delete Accessories & Assign',
                'request-scrap' => 'Submit Asset Scrap Requests',
                'approve-scrap' => 'Approve / Reject Asset Scrap Requests',
            ],
            'Admin Tools' => [
                'view-admin-links' => 'View Admin Tools / Quick Links',
                'manage-admin-links' => 'Create / Edit / Delete Admin Links',
            ],
            'Documentation' => [
                'view-documentation' => 'View Documentation',
                'manage-documentation' => 'Upload / Delete Documentation Files',
            ],
            'Print Manager' => [
                'view-print-manager' => 'View CUPS Print Manager',
                'manage-print-manager' => 'Manage CUPS Printers & Jobs',
            ],
            'Remote Browser' => [
                'view-browser-portal' => 'Launch & use a remote browser session',
                'share-browser-session' => 'Generate a share link to invite another user into their own session',
                'manage-browser-portal' => 'View / force-stop any session, view events, edit portal settings',
            ],
            'Forms' => [
                'manage-forms' => 'Manage Form Builder Templates & Submissions',
            ],
            'Email Marketing' => [
                'view-email-marketing' => 'View & use marketing portal (lists, subscribers, campaigns)',
                'manage-email-marketing' => 'Admin oversight (suppressions, quota, all campaigns)',
                'manage-email-marketing-settings' => 'Edit AWS SES credentials & sender domain',
            ],
            'Training (Courses)' => [
                'view-courses' => 'View training courses, certificates & marketing course campaigns',
                'manage-courses' => 'Create / edit / delete courses & manage issued certificates',
            ],
            'Recruitment' => [
                'view-candidates' => 'View & filter Teamtailor candidates',
                'reject-candidates' => 'Reject Teamtailor job applications',
            ],
            'Email Signatures' => [
                'manage-signatures' => 'Create / Edit / Delete Email Signature Templates',
            ],
            'Employee Home Portal' => [
                'view-announcements' => 'View Announcements',
                'manage-announcements' => 'Create / Edit / Delete Announcements',
                'manage-greeting-lines' => 'Edit Home Portal Greeting Lines',
                'manage-portal-documents' => 'Publish / Edit / Delete Employee Documents (manuals, IT policies)',
                'view-knowbe4-scores' => 'View All KnowBe4 Security Scores',
            ],
            'Ticketing' => [
                'create-tickets' => 'Raise Tickets in the IT Ticketing System',
                'create-tickets-for-others' => 'Raise Tickets on Behalf of Another Employee',
                'view-tickets' => 'View Ticket Submission History',
            ],
            'Access Gateway' => [
                'view-agw-audit' => 'View Access Gateway Audit Log',
                'manage-agw-allowlist' => 'Manage Access Gateway IP Allowlist',
                'manage-agw-settings' => 'Edit Access Gateway Settings (app URL, ACL toggle)',
            ],
            'AI Assistant' => [
                'manage-ai-assistant' => 'Manage AI Assistant (Settings, Knowledge Articles)',
                'view-ai-conversations' => 'View AI Assistant Conversations & Usage',
            ],
            'Attendance' => [
                'view-attendance' => 'View Attendance (BioTime check-in / check-out, employee mapping)',
                'manage-attendance' => 'Manage Attendance (BioTime sources, employee links, rebuild days)',
                'approve-attendance' => 'Approve & Lock Attendance Periods, Send to Oracle',
            ],
        ];
    }

    /**
     * Flat list of all permission slugs.
     */
    public static function allSlugs(): array
    {
        return collect(static::allPermissions())
            ->flatMap(fn ($perms) => array_keys($perms))
            ->all();
    }

    /**
     * Default permissions per role.
     */
    public static function defaultPermissions(): array
    {
        $all = static::allSlugs();
        $adminPerms = array_values(array_diff($all, [
            'manage-users', 'manage-permissions', 'manage-roles',
            'manage-credentials', 'manage-identity-settings',
            'manage-deploy-servers',
            'manage-email-marketing-settings',
        ]));
        $viewerPerms = [
            'view-branches', 'view-contacts',
            'view-activity-logs', 'view-phone-logs',
            'view-extensions', 'view-trunks',
            'view-network', 'view-assets', 'view-printers',
            'view-workflows', 'view-employees', 'view-noc',
            'view-dhcp-leases', 'view-sophos', 'view-fortigate', 'view-access-points', 'view-dns', 'view-admin-links',
            'view-syslog', 'view-agw-audit', 'view-deploy-servers',
            'create-tickets', 'view-tickets',
            // Registered late (see unregisteredSlugs()); these matched the
            // read-only grants their own seeding migrations already made.
            'view-phones', 'view-phone-firmware', 'view-printer-usage',
            'view-server-status', 'view-downloads', 'view-branch-agents',
            'view-voice-mesh', 'view-voice-quality',
        ];
        $hrPerms = [
            'manage-hr-portal',
            'submit-hr-onboarding',
            'submit-hr-offboarding',
            'submit-hr-employee-update',
            'view-workflows',
            'view-employees',
            'view-contacts',
            'view-browser-portal',
            'view-offboarding',
            'manage-offboarding',
            'create-tickets',
            'view-tickets',
            'view-announcements',
            'manage-announcements',
            'view-attendance',
            'manage-attendance',
            'approve-attendance',
        ];
        $marketingPerms = ['view-email-marketing', 'view-courses', 'manage-courses'];

        return [
            'super_admin' => $all,
            'admin' => $adminPerms,
            'hr' => $hrPerms,
            'viewer' => $viewerPerms,
            'browser_user' => ['view-browser-portal'],
            'marketing' => $marketingPerms,
        ];
    }

    /**
     * Get all permission slugs for a role (cached per-request).
     */
    public static function forRole(string $role): array
    {
        if (! isset(static::$cache[$role])) {
            try {
                static::$cache[$role] = static::where('role', $role)->pluck('permission')->all();
            } catch (\Throwable) {
                // Table missing (fresh checkout before migrate): grant nothing
                // rather than fataling inside a boot-time gate closure.
                static::$cache[$role] = [];
            }
        }

        return static::$cache[$role];
    }

    /**
     * Permission slugs that are granted in the database but absent from
     * allPermissions() above.
     *
     * These exist because a subsystem shipped a `permission:` route gate and a
     * seeding migration without adding the slug to the registry. They were
     * invisible in the permissions matrix and — worse — the matrix save used to
     * `truncate()` the table and re-insert only known slugs, silently deleting
     * every grant for them and locking those pages to super_admin with no UI
     * left to restore them.
     *
     * syncRoles() below never touches an unregistered slug, so a future
     * omission degrades to "not editable in the UI" instead of "destroyed".
     *
     * @return array<int,string>
     */
    public static function unregisteredSlugs(): array
    {
        try {
            $granted = static::query()->distinct()->pluck('permission')->all();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_diff($granted, static::allSlugs()));
    }

    /**
     * Replace the permission sets for the given roles.
     *
     * Only rows whose permission is in the registry are deleted, so a grant for
     * an unregistered slug (see unregisteredSlugs()) survives a matrix save.
     * Scoped to the submitted roles as well, so saving one role can never
     * affect another.
     *
     * @param  array<string,array<int,string>>  $grants  role slug => permission slugs
     */
    public static function syncRoles(array $grants): void
    {
        $registry = static::allSlugs();
        $now = now();

        \Illuminate\Support\Facades\DB::transaction(function () use ($grants, $registry, $now) {
            foreach ($grants as $role => $slugs) {
                $keep = array_values(array_intersect($slugs, $registry));

                static::where('role', $role)
                    ->whereIn('permission', $registry)
                    ->delete();

                if ($keep === []) {
                    continue;
                }

                static::insert(array_map(fn ($slug) => [
                    'role' => $role,
                    'permission' => $slug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $keep));
            }
        });

        static::clearCache();
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
