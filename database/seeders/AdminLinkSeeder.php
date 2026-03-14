<?php

namespace Database\Seeders;

use App\Models\AdminLink;
use App\Models\AdminLinkCategory;
use Illuminate\Database\Seeder;

class AdminLinkSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Microsoft',      'icon' => 'microsoft',       'sort_order' => 1],
            ['name' => 'Email Security',  'icon' => 'envelope-check',  'sort_order' => 2],
            ['name' => 'Network',         'icon' => 'hdd-network',     'sort_order' => 3],
            ['name' => 'Infrastructure',  'icon' => 'server',          'sort_order' => 4],
            ['name' => 'Cloud',           'icon' => 'cloud',           'sort_order' => 5],
            ['name' => 'Monitoring',      'icon' => 'graph-up',        'sort_order' => 6],
            ['name' => 'Security',        'icon' => 'shield-lock',     'sort_order' => 7],
        ];

        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[$cat['name']] = AdminLinkCategory::firstOrCreate(
                ['name' => $cat['name']],
                $cat
            )->id;
        }

        $links = [
            // Microsoft
            ['name' => 'Microsoft 365 Admin',  'category' => 'Microsoft', 'url' => 'https://admin.microsoft.com',          'icon' => 'microsoft',          'description' => 'Microsoft 365 Administration Center', 'sort_order' => 1],
            ['name' => 'Exchange Admin',        'category' => 'Microsoft', 'url' => 'https://admin.exchange.microsoft.com', 'icon' => 'envelope',           'description' => 'Exchange Online Administration',      'sort_order' => 2],
            ['name' => 'Azure Portal',          'category' => 'Microsoft', 'url' => 'https://portal.azure.com',            'icon' => 'cloud',              'description' => 'Microsoft Azure Portal',               'sort_order' => 3],
            ['name' => 'Entra ID',              'category' => 'Microsoft', 'url' => 'https://entra.microsoft.com',         'icon' => 'person-badge',       'description' => 'Azure Active Directory / Entra ID',   'sort_order' => 4],
            ['name' => 'Teams Admin',           'category' => 'Microsoft', 'url' => 'https://admin.teams.microsoft.com',   'icon' => 'chat-dots',          'description' => 'Microsoft Teams Administration',      'sort_order' => 5],
            ['name' => 'Intune',                'category' => 'Microsoft', 'url' => 'https://intune.microsoft.com',        'icon' => 'laptop',             'description' => 'Endpoint Device Management',          'sort_order' => 6],
            ['name' => 'Security Center',       'category' => 'Microsoft', 'url' => 'https://security.microsoft.com',      'icon' => 'shield-check',       'description' => 'Microsoft 365 Security Center',       'sort_order' => 7],
            ['name' => 'Defender',              'category' => 'Microsoft', 'url' => 'https://security.microsoft.com/v2',   'icon' => 'shield-lock',        'description' => 'Microsoft Defender Portal',            'sort_order' => 8],

            // Email Security
            ['name' => 'Mimecast',    'category' => 'Email Security', 'url' => 'https://login.mimecast.com',   'icon' => 'envelope-check',  'description' => 'Mimecast Email Security',   'sort_order' => 1],
            ['name' => 'Proofpoint',  'category' => 'Email Security', 'url' => 'https://login.proofpoint.com', 'icon' => 'shield-check',    'description' => 'Proofpoint Email Security',  'sort_order' => 2],
            ['name' => 'SpamTitan',   'category' => 'Email Security', 'url' => 'https://spamtitan.com/login',  'icon' => 'funnel',          'description' => 'SpamTitan Email Gateway',    'sort_order' => 3],

            // Network
            ['name' => 'Meraki Dashboard',  'category' => 'Network', 'url' => 'https://dashboard.meraki.com', 'icon' => 'wifi',             'description' => 'Cisco Meraki Dashboard',           'sort_order' => 1],
            ['name' => 'Sophos Firewall',   'category' => 'Network', 'url' => 'https://central.sophos.com',   'icon' => 'bricks',           'description' => 'Sophos Central Firewall Manager',  'sort_order' => 2],
            ['name' => 'FortiGate',         'category' => 'Network', 'url' => 'https://fortigate.fortinet.com','icon' => 'shield',          'description' => 'FortiGate Firewall Console',       'sort_order' => 3],

            // Infrastructure
            ['name' => 'VMware',     'category' => 'Infrastructure', 'url' => 'https://vcenter.local',      'icon' => 'pc-display',    'description' => 'VMware vCenter Server',   'sort_order' => 1],
            ['name' => 'Veeam',      'category' => 'Infrastructure', 'url' => 'https://veeam.local',        'icon' => 'arrow-repeat',  'description' => 'Veeam Backup & Recovery', 'sort_order' => 2],

            // Cloud
            ['name' => 'AWS Console',    'category' => 'Cloud', 'url' => 'https://console.aws.amazon.com', 'icon' => 'cloud',        'description' => 'Amazon Web Services Console', 'sort_order' => 1],
            ['name' => 'Cloudflare',     'category' => 'Cloud', 'url' => 'https://dash.cloudflare.com',    'icon' => 'globe',        'description' => 'Cloudflare Dashboard',         'sort_order' => 2],
            ['name' => 'DNS Management', 'category' => 'Cloud', 'url' => 'https://dash.cloudflare.com',    'icon' => 'globe2',       'description' => 'DNS Zone Management',           'sort_order' => 3],

            // Monitoring
            ['name' => 'NOC Dashboard',    'category' => 'Monitoring', 'url' => '#',  'icon' => 'display',      'description' => 'Network Operations Center Dashboard', 'sort_order' => 1],
            ['name' => 'SNMP Monitoring',  'category' => 'Monitoring', 'url' => '#',  'icon' => 'graph-up',     'description' => 'SNMP Device Monitoring',               'sort_order' => 2],
        ];

        foreach ($links as $link) {
            AdminLink::firstOrCreate(
                ['name' => $link['name'], 'category_id' => $catMap[$link['category']]],
                [
                    'url'         => $link['url'],
                    'icon'        => $link['icon'],
                    'description' => $link['description'],
                    'sort_order'  => $link['sort_order'],
                    'is_active'   => true,
                ]
            );
        }
    }
}
