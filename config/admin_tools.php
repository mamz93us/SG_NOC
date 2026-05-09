<?php

/**
 * Curated admin-tool catalog used by the welcome-screen "Add quick link" picker.
 *
 * Each entry: { key, label, route, icon, permission }
 *   - key:        unique slug stored as the source identifier
 *   - route:      named route the tool lives at
 *   - permission: Gate ability slug (null = visible to anyone authenticated)
 *
 * The picker hides tools the current user lacks permission for, and tools whose
 * route is not registered in the app.
 */

return [

    // ── Settings / Configuration ──────────────────────────────────
    ['key' => 'settings',          'label' => 'General Settings',      'route' => 'admin.settings.index',                'icon' => 'bi-sliders',         'permission' => 'manage-settings'],
    ['key' => 'locations',         'label' => 'Locations',             'route' => 'admin.settings.locations',            'icon' => 'bi-geo-alt-fill',    'permission' => 'manage-settings'],
    ['key' => 'branches',          'label' => 'Branches',              'route' => 'admin.branches.index',                'icon' => 'bi-building',        'permission' => 'view-branches'],
    ['key' => 'departments',       'label' => 'Departments',           'route' => 'admin.settings.departments',          'icon' => 'bi-grid-1x2-fill',   'permission' => 'manage-settings'],
    ['key' => 'allowed-domains',   'label' => 'Allowed Domains',       'route' => 'admin.settings.domains',              'icon' => 'bi-globe',           'permission' => 'manage-allowed-domains'],
    ['key' => 'asset-types',       'label' => 'Asset Types & Codes',   'route' => 'admin.settings.asset-types',          'icon' => 'bi-tags-fill',       'permission' => 'manage-settings'],
    ['key' => 'internet-levels',   'label' => 'Internet Access Levels','route' => 'admin.settings.internet-access-levels.index', 'icon' => 'bi-wifi',     'permission' => 'manage-settings'],
    ['key' => 'provisioning',      'label' => 'Provisioning Licenses', 'route' => 'admin.settings.provisioning-licenses','icon' => 'bi-patch-check-fill','permission' => 'manage-settings'],

    // ── User & Access ─────────────────────────────────────────────
    ['key' => 'users',             'label' => 'Users',                 'route' => 'admin.users.index',                   'icon' => 'bi-person-badge-fill','permission' => 'manage-users'],
    ['key' => 'permissions',       'label' => 'Permissions',           'route' => 'admin.permissions.index',             'icon' => 'bi-shield-lock-fill','permission' => 'manage-permissions'],

    // ── Platform / Logs ───────────────────────────────────────────
    ['key' => 'notification-rules','label' => 'Notification Rules',    'route' => 'admin.notification-rules.index',      'icon' => 'bi-funnel-fill',     'permission' => 'manage-notification-rules'],
    ['key' => 'sync-status',       'label' => 'Sync Status',           'route' => 'admin.sync-status',                   'icon' => 'bi-arrow-repeat',    'permission' => null],
    ['key' => 'email-log',         'label' => 'Email Log',             'route' => 'admin.email-log.index',               'icon' => 'bi-envelope-check',  'permission' => 'view-email-logs'],
    ['key' => 'license-monitors',  'label' => 'License Monitors',      'route' => 'admin.license-monitors.index',        'icon' => 'bi-eye',             'permission' => 'manage-license-monitors'],
    ['key' => 'activity-logs',     'label' => 'Activity Log',          'route' => 'admin.activity-logs',                 'icon' => 'bi-shield-check',    'permission' => 'view-activity-logs'],
    ['key' => 'phone-logs',        'label' => 'Phone XML Logs',        'route' => 'admin.phone-logs.index',              'icon' => 'bi-filetype-xml',    'permission' => 'view-phone-logs'],

    // ── Tools ─────────────────────────────────────────────────────
    ['key' => 'documentation',     'label' => 'Documentation',         'route' => 'admin.documentation.index',           'icon' => 'bi-book',            'permission' => 'view-documentation'],
    ['key' => 'telnet',            'label' => 'Telnet Client',         'route' => 'admin.telnet.index',                  'icon' => 'bi-terminal',        'permission' => 'view-noc'],
    ['key' => 'browser-portal',    'label' => 'Browser Portal',        'route' => 'admin.browser-portal.index',          'icon' => 'bi-window',          'permission' => null],
    ['key' => 'admin-links',       'label' => 'Admin Tools (Manage)',  'route' => 'admin.admin-links.index',             'icon' => 'bi-grid-3x3-gap-fill','permission' => 'view-admin-links'],
    ['key' => 'noc-dashboard',     'label' => 'NOC Command Center',    'route' => 'admin.noc.dashboard',                 'icon' => 'bi-speedometer2',    'permission' => 'view-noc'],
    ['key' => 'phonebook',         'label' => 'Phonebook Overview',    'route' => 'admin.phonebook.overview',            'icon' => 'bi-journals',        'permission' => null],

];
