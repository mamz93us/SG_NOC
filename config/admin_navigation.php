<?php

/**
 * Single source of truth for the v2 admin sidebar AND the Ctrl+K command palette.
 *
 * Each entry has:
 *   - label:      group heading
 *   - items[]:    list of { label, route, icon, permission }
 *
 * Permission is a Gate ability slug (or null for everyone). Items whose route
 * doesn't exist are skipped silently — see partials/sidebar-nav.blade.php.
 */

return [

    [
        'label' => 'Welcome',
        'items' => [
            ['label' => 'Dashboard',           'route' => 'admin.dashboard',           'icon' => 'bi-house-door',     'permission' => null],
            ['label' => 'NOC Command Center',  'route' => 'admin.noc.dashboard',       'icon' => 'bi-speedometer2',   'permission' => 'view-noc'],
            ['label' => 'Phonebook Overview',  'route' => 'admin.phonebook.overview',  'icon' => 'bi-journals',       'permission' => null],
        ],
    ],

    [
        'label' => 'VoIP',
        'items' => [
            ['label' => 'Contacts',     'route' => 'admin.contacts.index',           'icon' => 'bi-person-lines-fill', 'permission' => 'view-contacts'],
            ['label' => 'Extensions',   'route' => 'admin.extensions.index',         'icon' => 'bi-telephone',          'permission' => 'view-extensions'],
            ['label' => 'Trunks',       'route' => 'admin.trunks.index',             'icon' => 'bi-hdd-network-fill',   'permission' => 'view-trunks'],
            ['label' => 'UCM Status',   'route' => 'admin.gdms.ucm',                 'icon' => 'bi-cloud-check-fill',   'permission' => 'view-extensions'],
            ['label' => 'Landlines',    'route' => 'admin.telecom.landlines.index',  'icon' => 'bi-telephone-inbound',  'permission' => 'view-extensions'],
        ],
    ],

    [
        'label' => 'Network',
        'items' => [
            ['label' => 'Meraki Overview',  'route' => 'admin.network.overview',         'icon' => 'bi-diagram-3-fill',   'permission' => 'view-network'],
            ['label' => 'VPN Hub',          'route' => 'admin.network.vpn.index',        'icon' => 'bi-shield-lock',      'permission' => 'manage-network-settings'],
            ['label' => 'ISP Connections',  'route' => 'admin.network.isp.index',        'icon' => 'bi-globe2',           'permission' => 'view-network'],
            ['label' => 'IP Reservations',  'route' => 'admin.network.ip-reservations.index', 'icon' => 'bi-hdd-rack',    'permission' => 'view-network'],
            ['label' => 'Diagnostics',      'route' => 'admin.network.diagnostics.index','icon' => 'bi-search',           'permission' => 'manage-network-settings'],
            ['label' => 'SNMP Monitoring',  'route' => 'admin.network.monitoring.index', 'icon' => 'bi-broadcast',        'permission' => 'manage-network-settings'],
            ['label' => 'IP Scanner',       'route' => 'admin.network.scanner.index',    'icon' => 'bi-radar',            'permission' => 'manage-network-settings'],
            ['label' => 'IPAM Subnets',     'route' => 'admin.network.ipam.index',       'icon' => 'bi-grid-3x3',         'permission' => 'view-network'],
            ['label' => 'DHCP Leases',      'route' => 'admin.network.dhcp.index',       'icon' => 'bi-hdd-network-fill', 'permission' => 'view-dhcp-leases'],
            ['label' => 'Sophos Firewalls', 'route' => 'admin.network.sophos.index',     'icon' => 'bi-shield-fill',      'permission' => 'view-sophos'],
            ['label' => 'DNS Accounts',     'route' => 'admin.network.dns.index',        'icon' => 'bi-globe',            'permission' => 'view-network'],
            ['label' => 'Topology Map',     'route' => 'admin.network.topology.index',   'icon' => 'bi-diagram-3',        'permission' => 'view-network'],
        ],
    ],

    [
        'label' => 'Assets',
        'items' => [
            ['label' => 'Devices',          'route' => 'admin.devices.index',     'icon' => 'bi-cpu',                'permission' => 'view-assets'],
            ['label' => 'Printers',         'route' => 'admin.printers.index',    'icon' => 'bi-printer',            'permission' => 'view-printers'],
            ['label' => 'Printer Dashboard','route' => 'admin.printers.dashboard','icon' => 'bi-printer-fill',       'permission' => 'view-printers'],
            ['label' => 'Credentials',      'route' => 'admin.credentials.index', 'icon' => 'bi-key-fill',           'permission' => 'view-credentials'],
            ['label' => 'Warranty',         'route' => 'admin.devices.warranty',  'icon' => 'bi-shield-check',       'permission' => 'view-assets'],
        ],
    ],

    [
        'label' => 'Monitor',
        'items' => [
            ['label' => 'NOC Dashboard',   'route' => 'admin.noc.dashboard',         'icon' => 'bi-speedometer2',          'permission' => 'view-noc'],
            ['label' => 'NOC Events',      'route' => 'admin.noc.events',            'icon' => 'bi-broadcast-pin',         'permission' => 'view-noc'],
            ['label' => 'Alerts Feed',     'route' => 'admin.noc.alerts',            'icon' => 'bi-bell-fill',             'permission' => 'view-noc'],
            ['label' => 'Incidents',       'route' => 'admin.noc.incidents.index',   'icon' => 'bi-exclamation-triangle',  'permission' => 'view-incidents'],
            ['label' => 'Wallboard',       'route' => 'admin.noc.wallboard',         'icon' => 'bi-display',               'permission' => 'view-noc'],
            ['label' => 'Syslog',          'route' => 'admin.syslog.index',          'icon' => 'bi-journal-text',          'permission' => 'view-syslog'],
        ],
    ],

    [
        'label' => 'Identity',
        'items' => [
            ['label' => 'Identity Users',  'route' => 'admin.identity.users',     'icon' => 'bi-people-fill',  'permission' => 'view-identity'],
            ['label' => 'Sync Logs',       'route' => 'admin.identity.sync-logs', 'icon' => 'bi-arrow-repeat', 'permission' => 'view-identity'],
        ],
    ],

    [
        'label' => 'Workflows',
        'items' => [
            ['label' => 'My Requests',     'route' => 'admin.workflows.my-requests', 'icon' => 'bi-list-check',     'permission' => 'view-workflows'],
            ['label' => 'All Workflows',   'route' => 'admin.workflows.index',       'icon' => 'bi-diagram-2',      'permission' => 'view-workflows'],
            ['label' => 'New Request',     'route' => 'admin.workflows.create',      'icon' => 'bi-send-plus',      'permission' => 'manage-workflows'],
            ['label' => 'Pending Approvals','route'=> 'admin.workflows.pending',     'icon' => 'bi-hourglass-split','permission' => 'manage-workflows'],
            ['label' => 'Templates',       'route' => 'admin.workflow-templates.index','icon'=> 'bi-file-earmark-code','permission' => 'manage-workflows'],
        ],
    ],

    [
        'label' => 'Forms',
        'items' => [
            ['label' => 'Form Builder',    'route' => 'admin.forms.index', 'icon' => 'bi-ui-checks-grid', 'permission' => 'manage-forms'],
        ],
    ],

    [
        'label' => 'Tools',
        'items' => [
            ['label' => 'Documentation',   'route' => 'admin.documentation.index',  'icon' => 'bi-book',             'permission' => 'view-documentation'],
            ['label' => 'Telnet Client',   'route' => 'admin.telnet.index',         'icon' => 'bi-terminal',         'permission' => 'view-noc'],
            ['label' => 'Admin Links',     'route' => 'admin.admin-links.index',    'icon' => 'bi-grid-3x3-gap',     'permission' => 'view-admin-links'],
        ],
    ],

    [
        'label' => 'Logs',
        'items' => [
            ['label' => 'Activity Log',    'route' => 'admin.activity-logs',         'icon' => 'bi-shield-check',  'permission' => 'view-activity-logs'],
            ['label' => 'Phone XML Logs',  'route' => 'admin.phone-logs.index',      'icon' => 'bi-filetype-xml',  'permission' => 'view-logs'],
            ['label' => 'Notifications',   'route' => 'admin.notifications.index',   'icon' => 'bi-bell',          'permission' => null],
        ],
    ],

    [
        'label' => 'Admin',
        'items' => [
            ['label' => 'Users',           'route' => 'admin.users.index',        'icon' => 'bi-person-badge',  'permission' => 'manage-users'],
            ['label' => 'Permissions',     'route' => 'admin.permissions.index',  'icon' => 'bi-shield-lock',   'permission' => 'manage-permissions'],
            ['label' => 'Settings',        'route' => 'admin.settings.index',     'icon' => 'bi-gear',          'permission' => 'manage-settings'],
        ],
    ],

];
