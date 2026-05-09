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
            ['label' => 'Dashboard',            'route' => 'admin.dashboard',           'icon' => 'bi-house-door',     'permission' => null],
            ['label' => 'NOC Command Center',   'route' => 'admin.noc.dashboard',       'icon' => 'bi-speedometer2',   'permission' => 'view-noc'],
            ['label' => 'Phonebook Overview',   'route' => 'admin.phonebook.overview',  'icon' => 'bi-journals',       'permission' => null],
        ],
    ],

    [
        'label' => 'VoIP',
        'items' => [
            ['label' => 'Contacts',             'route' => 'admin.contacts.index',           'icon' => 'bi-person-lines-fill', 'permission' => 'view-contacts'],
            ['label' => 'Extensions',           'route' => 'admin.extensions.index',         'icon' => 'bi-telephone',          'permission' => 'view-extensions'],
            ['label' => 'Trunks',               'route' => 'admin.trunks.index',             'icon' => 'bi-hdd-network-fill',   'permission' => 'view-trunks'],
            ['label' => 'UCM Status',           'route' => 'admin.gdms.ucm',                 'icon' => 'bi-cloud-check-fill',   'permission' => 'view-extensions'],
            ['label' => 'Landlines',            'route' => 'admin.telecom.landlines.index',  'icon' => 'bi-telephone-inbound',  'permission' => 'view-extensions'],
        ],
    ],

    [
        'label' => 'Network',
        'items' => [
            ['label' => 'Meraki Overview',      'route' => 'admin.network.overview',              'icon' => 'bi-diagram-3-fill',   'permission' => 'view-network'],
            ['label' => 'Switches',             'route' => 'admin.network.switches',              'icon' => 'bi-hdd-stack-fill',   'permission' => 'view-network'],
            ['label' => 'Clients',              'route' => 'admin.network.clients',               'icon' => 'bi-laptop',           'permission' => 'view-network'],
            ['label' => 'Network Events',       'route' => 'admin.network.events',                'icon' => 'bi-activity',         'permission' => 'view-network-events'],
            ['label' => 'VPN Hub',              'route' => 'admin.network.vpn.index',             'icon' => 'bi-shield-lock',      'permission' => 'manage-network-settings'],
            ['label' => 'ISP Connections',      'route' => 'admin.network.isp.index',             'icon' => 'bi-globe2',           'permission' => 'view-network'],
            ['label' => 'IP Reservations',      'route' => 'admin.network.ip-reservations.index', 'icon' => 'bi-hdd-rack',         'permission' => 'view-network'],
            ['label' => 'Diagnostics',          'route' => 'admin.network.diagnostics.index',     'icon' => 'bi-search',           'permission' => 'manage-network-settings'],
            ['label' => 'SNMP Monitoring',      'route' => 'admin.network.monitoring.index',      'icon' => 'bi-broadcast',        'permission' => 'manage-network-settings'],
            ['label' => 'SNMP Hosts',           'route' => 'admin.network.monitoring.hosts.list', 'icon' => 'bi-list-check',       'permission' => 'manage-network-settings'],
            ['label' => 'SNMP Dashboard',       'route' => 'admin.network.monitoring.dashboard',  'icon' => 'bi-speedometer',      'permission' => 'manage-network-settings'],
            ['label' => 'Switch QoS',           'route' => 'admin.switch-qos.dashboard',          'icon' => 'bi-graph-up-arrow',   'permission' => 'manage-network-settings'],
            ['label' => 'Voice Quality',        'route' => 'admin.voice-quality.dashboard',       'icon' => 'bi-soundwave',        'permission' => 'manage-network-settings'],
            ['label' => 'Workers & Tasks',      'route' => 'admin.network.workers.index',         'icon' => 'bi-cpu-fill',         'permission' => 'manage-network-settings'],
            ['label' => 'IP Scanner',           'route' => 'admin.network.scanner.index',         'icon' => 'bi-radar',            'permission' => 'manage-network-settings'],
            ['label' => 'SLA Dashboard',        'route' => 'admin.network.sla.index',             'icon' => 'bi-graph-up',         'permission' => 'view-network'],
            ['label' => 'IPAM Subnets',         'route' => 'admin.network.ipam.index',            'icon' => 'bi-grid-3x3',         'permission' => 'view-network'],
            ['label' => 'DHCP Leases',          'route' => 'admin.network.dhcp.index',            'icon' => 'bi-hdd-network-fill', 'permission' => 'view-dhcp-leases'],
            ['label' => 'Sophos Firewalls',     'route' => 'admin.network.sophos.index',          'icon' => 'bi-shield-fill',      'permission' => 'view-sophos'],
            ['label' => 'RADIUS MACs',          'route' => 'admin.radius.macs.index',             'icon' => 'bi-fingerprint',      'permission' => 'manage-radius'],
            ['label' => 'NAS Clients',          'route' => 'admin.radius.nas.index',              'icon' => 'bi-router',           'permission' => 'manage-radius'],
            ['label' => 'VLAN Policy',          'route' => 'admin.radius.vlan.index',             'icon' => 'bi-diagram-3',        'permission' => 'manage-radius'],
            ['label' => 'DNS Accounts',         'route' => 'admin.network.dns.index',             'icon' => 'bi-globe',            'permission' => 'view-network'],
            ['label' => 'Domain Lookup',        'route' => 'admin.network.dns.lookup.index',      'icon' => 'bi-search',           'permission' => 'view-network'],
            ['label' => 'Topology Map',         'route' => 'admin.network.topology.index',        'icon' => 'bi-diagram-3',        'permission' => 'view-network'],
            ['label' => 'Port Map',             'route' => 'admin.network.port-map.index',        'icon' => 'bi-grid-1x2',         'permission' => 'view-network'],
        ],
    ],

    [
        'label' => 'Assets',
        'items' => [
            ['label' => 'Devices',              'route' => 'admin.devices.index',                 'icon' => 'bi-cpu',                'permission' => 'view-assets'],
            ['label' => 'Warranty',             'route' => 'admin.devices.warranty',              'icon' => 'bi-shield-check',       'permission' => 'view-assets'],
            ['label' => 'Firmware',             'route' => 'admin.devices.firmware',              'icon' => 'bi-cpu-fill',           'permission' => 'view-assets'],
            ['label' => 'Device Models',        'route' => 'admin.devices.models.index',          'icon' => 'bi-collection',         'permission' => 'view-assets'],
            ['label' => 'Phone Auto-Assign',    'route' => 'admin.devices.phone-auto-assign',     'icon' => 'bi-phone',              'permission' => 'view-assets'],
            ['label' => 'Import Devices',       'route' => 'admin.devices.import',                'icon' => 'bi-upload',             'permission' => 'view-assets'],
            ['label' => 'QR Scanner',           'route' => 'admin.devices.scan',                  'icon' => 'bi-qr-code-scan',       'permission' => 'view-assets'],
            ['label' => 'Printers',             'route' => 'admin.printers.index',                'icon' => 'bi-printer',            'permission' => 'view-printers'],
            ['label' => 'Printer Dashboard',    'route' => 'admin.printers.dashboard',            'icon' => 'bi-printer-fill',       'permission' => 'view-printers'],
            ['label' => 'Printer SNMP',         'route' => 'admin.printers.snmp.status',          'icon' => 'bi-printer',            'permission' => 'view-printers'],
            ['label' => 'Intune Groups',        'route' => 'admin.intune-groups.index',           'icon' => 'bi-microsoft',          'permission' => 'view-identity'],
            ['label' => 'Network Discovery',    'route' => 'admin.network-discovery.index',       'icon' => 'bi-search-heart',       'permission' => 'view-assets'],
            ['label' => 'Print Manager',        'route' => 'admin.print-manager.index',           'icon' => 'bi-files',              'permission' => 'view-printers'],
            ['label' => 'Credentials',          'route' => 'admin.credentials.index',             'icon' => 'bi-key-fill',           'permission' => 'view-credentials'],
            ['label' => 'Employees',            'route' => 'admin.employees.index',               'icon' => 'bi-person-workspace',   'permission' => 'view-employees'],
        ],
    ],

    [
        'label' => 'ITAM',
        'items' => [
            ['label' => 'ITAM Dashboard',       'route' => 'admin.itam.dashboard',                'icon' => 'bi-boxes',              'permission' => 'view-assets'],
            ['label' => 'Suppliers',            'route' => 'admin.itam.suppliers.index',          'icon' => 'bi-building',           'permission' => 'view-assets'],
            ['label' => 'Licenses',             'route' => 'admin.itam.licenses.index',           'icon' => 'bi-card-checklist',     'permission' => 'view-assets'],
            ['label' => 'Accessories',          'route' => 'admin.itam.accessories.index',        'icon' => 'bi-headset',            'permission' => 'view-assets'],
            ['label' => 'Azure Sync',           'route' => 'admin.itam.azure.index',              'icon' => 'bi-cloud-arrow-down',   'permission' => 'view-assets'],
            ['label' => 'MAC Addresses',        'route' => 'admin.itam.mac-address',              'icon' => 'bi-fingerprint',        'permission' => 'view-assets'],
            ['label' => 'Asset Transfer',       'route' => 'admin.itam.transfer.index',           'icon' => 'bi-arrow-left-right',   'permission' => 'view-assets'],
            ['label' => 'Stores',               'route' => 'admin.itam.stores.index',             'icon' => 'bi-shop',               'permission' => 'view-assets'],
            ['label' => 'Scrap Requests',       'route' => 'admin.itam.scrap.index',              'icon' => 'bi-trash',              'permission' => 'view-assets'],
            ['label' => 'ITAM Reports',         'route' => 'admin.itam.reports.index',            'icon' => 'bi-bar-chart',          'permission' => 'view-assets'],
        ],
    ],

    [
        'label' => 'Monitor',
        'items' => [
            ['label' => 'NOC Dashboard',        'route' => 'admin.noc.dashboard',                 'icon' => 'bi-speedometer2',          'permission' => 'view-noc'],
            ['label' => 'NOC Events',           'route' => 'admin.noc.events',                    'icon' => 'bi-broadcast-pin',         'permission' => 'view-noc'],
            ['label' => 'NOC Extensions',       'route' => 'admin.noc.extensions',                'icon' => 'bi-telephone-fill',        'permission' => 'view-noc'],
            ['label' => 'Alerts Feed',          'route' => 'admin.noc.alerts',                    'icon' => 'bi-bell-fill',             'permission' => 'view-noc'],
            ['label' => 'Alert Rules',          'route' => 'admin.alerts.dashboard',              'icon' => 'bi-sliders',               'permission' => 'manage-noc'],
            ['label' => 'Incidents',            'route' => 'admin.noc.incidents.index',           'icon' => 'bi-exclamation-triangle',  'permission' => 'view-incidents'],
            ['label' => 'Wallboard',            'route' => 'admin.noc.wallboard',                 'icon' => 'bi-display',               'permission' => 'view-noc'],
            ['label' => 'Branch Logs',          'route' => 'admin.logs.branches.index',           'icon' => 'bi-journal-text',          'permission' => 'view-syslog'],
            ['label' => 'Branch · Sophos',      'route' => 'admin.logs.branches.sophos',          'icon' => 'bi-shield-fill',           'permission' => 'view-syslog'],
            ['label' => 'Branch · UCM',         'route' => 'admin.logs.branches.ucm',             'icon' => 'bi-server',                'permission' => 'view-syslog'],
            ['label' => 'Log Collectors',       'route' => 'admin.branches.log-collectors.index', 'icon' => 'bi-hdd-network',           'permission' => 'view-syslog'],
            ['label' => 'SNMP Devices',         'route' => 'admin.snmp-devices.index',            'icon' => 'bi-broadcast',             'permission' => 'manage-network-settings'],
            ['label' => 'Syslog',               'route' => 'admin.syslog.index',                  'icon' => 'bi-journal-text',          'permission' => 'view-syslog'],
        ],
    ],

    [
        'label' => 'Identity',
        'items' => [
            ['label' => 'Identity Users',       'route' => 'admin.identity.users',                'icon' => 'bi-people-fill',     'permission' => 'view-identity'],
            ['label' => 'Licenses',             'route' => 'admin.identity.licenses',             'icon' => 'bi-card-checklist',  'permission' => 'view-identity'],
            ['label' => 'Groups',               'route' => 'admin.identity.groups',               'icon' => 'bi-people',          'permission' => 'view-identity'],
            ['label' => 'Contact Sync',         'route' => 'admin.identity.contact-sync',         'icon' => 'bi-arrow-left-right','permission' => 'manage-identity'],
            ['label' => 'Sync Logs',            'route' => 'admin.identity.sync-logs',            'icon' => 'bi-arrow-repeat',    'permission' => 'view-identity'],
        ],
    ],

    [
        'label' => 'Workflows',
        'items' => [
            ['label' => 'My Requests',          'route' => 'admin.workflows.my-requests',         'icon' => 'bi-list-check',       'permission' => 'view-workflows'],
            ['label' => 'All Workflows',        'route' => 'admin.workflows.index',               'icon' => 'bi-diagram-2',        'permission' => 'view-workflows'],
            ['label' => 'New Request',          'route' => 'admin.workflows.create',              'icon' => 'bi-send-plus',        'permission' => 'manage-workflows'],
            ['label' => 'Pending Approvals',    'route' => 'admin.workflows.pending',             'icon' => 'bi-hourglass-split',  'permission' => 'manage-workflows'],
            ['label' => 'Templates',            'route' => 'admin.workflow-templates.index',      'icon' => 'bi-file-earmark-code','permission' => 'manage-workflows'],
        ],
    ],

    [
        'label' => 'Forms',
        'items' => [
            ['label' => 'Form Builder',         'route' => 'admin.forms.index',                   'icon' => 'bi-ui-checks-grid',  'permission' => 'manage-forms'],
            ['label' => 'New Form',             'route' => 'admin.forms.create',                  'icon' => 'bi-plus-square',     'permission' => 'manage-forms'],
        ],
    ],

    [
        'label' => 'Tools',
        'items' => [
            ['label' => 'Documentation',        'route' => 'admin.documentation.index',           'icon' => 'bi-book',             'permission' => 'view-documentation'],
            ['label' => 'Telnet Client',        'route' => 'admin.telnet.index',                  'icon' => 'bi-terminal',         'permission' => 'view-noc'],
            ['label' => 'Admin Links',          'route' => 'admin.admin-links.index',             'icon' => 'bi-grid-3x3-gap',     'permission' => 'view-admin-links'],
            ['label' => 'Browser Portal',       'route' => 'admin.browser-portal.index',          'icon' => 'bi-window',           'permission' => 'view-browser-portal'],
            ['label' => 'Browser Events',       'route' => 'admin.browser-portal.events',         'icon' => 'bi-clock-history',    'permission' => 'view-browser-portal'],
            ['label' => 'Browser Settings',     'route' => 'admin.browser-portal.settings',       'icon' => 'bi-gear',             'permission' => 'manage-browser-portal'],
        ],
    ],

    [
        'label' => 'Logs',
        'items' => [
            ['label' => 'Activity Log',         'route' => 'admin.activity-logs',                 'icon' => 'bi-shield-check',   'permission' => 'view-activity-logs'],
            ['label' => 'Phone XML Logs',       'route' => 'admin.phone-logs.index',              'icon' => 'bi-filetype-xml',   'permission' => 'view-logs'],
            ['label' => 'Email Log',            'route' => 'admin.email-log.index',               'icon' => 'bi-envelope',       'permission' => 'view-logs'],
            ['label' => 'License Monitors',     'route' => 'admin.license-monitors.index',        'icon' => 'bi-eye',            'permission' => 'view-logs'],
            ['label' => 'Sync Status',          'route' => 'admin.sync-status',                   'icon' => 'bi-arrow-clockwise','permission' => 'view-logs'],
            ['label' => 'Notifications',        'route' => 'admin.notifications.index',           'icon' => 'bi-bell',           'permission' => null],
        ],
    ],

    [
        'label' => 'Admin',
        'items' => [
            ['label' => 'Settings',             'route' => 'admin.settings.index',                'icon' => 'bi-gear',           'permission' => 'manage-settings'],
            ['label' => 'Locations',            'route' => 'admin.settings.locations',            'icon' => 'bi-geo-alt',        'permission' => 'manage-settings'],
            ['label' => 'Branches',             'route' => 'admin.branches.index',                'icon' => 'bi-diagram-3',      'permission' => 'view-branches'],
            ['label' => 'Departments',          'route' => 'admin.settings.departments',          'icon' => 'bi-building',       'permission' => 'manage-settings'],
            ['label' => 'Allowed Domains',      'route' => 'admin.settings.domains',              'icon' => 'bi-globe',          'permission' => 'manage-settings'],
            ['label' => 'Asset Types',          'route' => 'admin.settings.asset-types',          'icon' => 'bi-tags',           'permission' => 'manage-settings'],
            ['label' => 'Internet Levels',      'route' => 'admin.settings.internet-access-levels.index', 'icon' => 'bi-shield-shaded', 'permission' => 'manage-settings'],
            ['label' => 'Provisioning',         'route' => 'admin.settings.provisioning-licenses','icon' => 'bi-card-list',      'permission' => 'manage-settings'],
            ['label' => 'Notification Rules',   'route' => 'admin.notification-rules.index',      'icon' => 'bi-bell-slash',     'permission' => 'manage-noc'],
            ['label' => 'Users',                'route' => 'admin.users.index',                   'icon' => 'bi-person-badge',   'permission' => 'manage-users'],
            ['label' => 'Permissions',          'route' => 'admin.permissions.index',             'icon' => 'bi-shield-lock',    'permission' => 'manage-permissions'],
        ],
    ],

];
