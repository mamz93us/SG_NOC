<!DOCTYPE html>
<html lang="en"
      data-bs-theme="{{ Auth::check() && Auth::user()->dark_mode ? 'dark' : 'light' }}"
      x-data="{ dark: {{ Auth::check() && Auth::user()->dark_mode ? 'true' : 'false' }} }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SG NOC</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { background: #f8f9fa; }
        [data-bs-theme="dark"] body { background: #1a1d21; }
        [data-bs-theme="dark"] .card { background-color: #212529; border-color: #373b3e; }
        [data-bs-theme="dark"] .table { --bs-table-bg: #212529; }
        [data-bs-theme="dark"] .avatar-circle { box-shadow: 0 0 0 2px rgba(255,255,255,.15); }
        .dark-mode-toggle { cursor: pointer; font-size: 1.15rem; transition: transform .2s ease; }
        .dark-mode-toggle:hover { transform: scale(1.15); }
        /* ── Compact navbar ────────────────────────────── */
        .navbar-nav .nav-link {
            font-size: 0.85rem;
            padding-left: 0.45rem;
            padding-right: 0.45rem;
        }
        .nav-link.active {
            font-weight: bold;
            background: rgba(255, 255, 255, 0.1);
        }
        /* ── Scrollable dropdowns ──────────────────────── */
        .navbar .dropdown-menu {
            max-height: 75vh;
            overflow-y: auto;
            font-size: 0.84rem;
        }
        /* ── Mega menu (multi-column for long dropdowns) ─ */
        @media (min-width: 992px) {
            .dropdown-menu.dropdown-mega {
                min-width: 440px;
                columns: 2;
                column-gap: 0;
            }
            .dropdown-menu.dropdown-mega > li {
                break-inside: avoid;
            }
        }
        .avatar-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-3 px-lg-4">
            @php $__settings = \App\Models\Setting::get(); @endphp
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold py-1" href="/admin">
                @if($__settings->company_logo)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($__settings->company_logo) }}"
                         alt="Logo" style="height:34px;width:auto;object-fit:contain;">
                @else
                    <span class="avatar-circle" style="background:linear-gradient(135deg,#1a56db,#6c47ff);font-size:15px;">SG</span>
                @endif
                <span class="d-none d-sm-inline">SG NOC</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">

                <ul class="navbar-nav me-auto">

                    {{-- ── VoIP dropdown (Contacts + UCM/Extensions) ── --}}
                    @canany(['view-contacts','view-extensions','view-trunks'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/contacts*','admin/extensions*','admin/trunks*','admin/gdms*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-telephone-fill me-1"></i>VoIP
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @can('view-contacts')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/contacts*') ? 'active' : '' }}"
                                   href="{{ route('admin.contacts.index') }}">
                                    <i class="bi bi-person-lines-fill me-2"></i>Contacts
                                </a>
                            </li>
                            @endcan
                            @canany(['view-extensions','view-trunks'])
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-hdd-stack me-1"></i>UCM / PBX</h6></li>
                            @can('view-extensions')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/extensions*') ? 'active' : '' }}"
                                   href="{{ route('admin.extensions.index') }}">
                                    <i class="bi bi-telephone me-2"></i>Extensions
                                </a>
                            </li>
                            @endcan
                            @can('view-trunks')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/trunks*') ? 'active' : '' }}"
                                   href="{{ route('admin.trunks.index') }}">
                                    <i class="bi bi-hdd-network-fill me-2"></i>Trunks
                                </a>
                            </li>
                            @endcan
                            @can('view-extensions')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/gdms*') ? 'active' : '' }}"
                                   href="{{ route('admin.gdms.ucm') }}">
                                    <i class="bi bi-cloud-check-fill me-2"></i>UCM Status
                                </a>
                            </li>
                            @endcan
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-telephone-inbound me-1"></i>Telecom</h6></li>
                            @can('view-extensions')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/telecom/landlines*') ? 'active' : '' }}"
                                   href="{{ route('admin.telecom.landlines.index') }}">
                                    <i class="bi bi-telephone me-2"></i>Landlines
                                </a>
                            </li>
                            @endcan
                            @endcanany
                        </ul>
                    </li>
                    @endcanany

                    {{-- ── Network dropdown ── --}}
                    @can('view-network')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/network*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-diagram-3-fill me-1"></i>Network
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega shadow">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.overview') ? 'active' : '' }}"
                                   href="{{ route('admin.network.overview') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>Meraki Overview
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.vpn.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.vpn.index') }}">
                                    <i class="bi bi-shield-lock me-2"></i>VPN Hub
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.isp.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.isp.index') }}">
                                    <i class="bi bi-globe2 me-2"></i>ISP Connections
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.ip-reservations.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.ip-reservations.index') }}">
                                    <i class="bi bi-hdd-rack me-2"></i>IP Reservations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.diagnostics.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.diagnostics.index') }}">
                                    <i class="bi bi-search me-2"></i>Diagnostics
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.monitoring.index') ? 'active' : '' }}"
                                   href="{{ route('admin.network.monitoring.index') }}">
                                    <i class="bi bi-broadcast me-2"></i>SNMP Monitoring
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.monitoring.hosts.list') ? 'active' : '' }}"
                                   href="{{ route('admin.network.monitoring.hosts.list') }}">
                                    <i class="bi bi-list-check me-2"></i>SNMP Hosts List
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.monitoring.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.network.monitoring.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2 text-info"></i>SNMP Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.switch-drops.*') ? 'active' : '' }}"
                                   href="{{ route('admin.switch-drops.dashboard') }}">
                                    <i class="bi bi-exclamation-triangle me-2 text-warning"></i>Switch Drop Monitor
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.voice-quality.*') ? 'active' : '' }}"
                                   href="{{ route('admin.voice-quality.dashboard') }}">
                                    <i class="bi bi-soundwave me-2 text-info"></i>Voice Quality
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.workers.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.workers.index') }}">
                                    <i class="bi bi-cpu-fill me-2"></i>Workers & Tasks
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.scanner.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.scanner.index') }}">
                                    <i class="bi bi-radar me-2"></i>IP Scanner
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.sla.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.sla.index') }}">
                                    <i class="bi bi-graph-up me-2"></i>SLA Dashboard
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-hdd-rack me-1"></i>IPAM / DHCP</h6></li>
                            @can('view-network')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.ipam.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.ipam.index') }}">
                                    <i class="bi bi-grid-3x3 me-2"></i>IPAM Subnets
                                </a>
                            </li>
                            @endcan
                            @can('view-dhcp-leases')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.dhcp.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.dhcp.index') }}">
                                    <i class="bi bi-hdd-network-fill me-2"></i>DHCP Leases
                                </a>
                            </li>
                            @endcan
                            @can('view-sophos')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.sophos.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.sophos.index') }}">
                                    <i class="bi bi-shield-fill me-2"></i>Sophos Firewalls
                                </a>
                            </li>
                            @endcan
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.topology.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.topology.index') }}">
                                    <i class="bi bi-diagram-3 me-2"></i>Topology Map
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.port-map.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.port-map.index') }}">
                                    <i class="bi bi-grid-3x3-gap me-2"></i>Port Map
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.switches') ? 'active' : '' }}"
                                   href="{{ route('admin.network.switches') }}">
                                    <i class="bi bi-hdd-network me-2"></i>Switches
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.clients') ? 'active' : '' }}"
                                   href="{{ route('admin.network.clients') }}">
                                    <i class="bi bi-laptop me-2"></i>Clients
                                </a>
                            </li>
                            @can('view-network-events')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.events') ? 'active' : '' }}"
                                   href="{{ route('admin.network.events') }}">
                                    <i class="bi bi-clock-history me-2"></i>Change Monitor
                                </a>
                            </li>
                            @endcan
                        </ul>
                    </li>
                    @endcan

                    {{-- ── Assets + ITAM dropdown ── --}}
                    @canany(['view-assets','view-printers','view-credentials','view-employees','view-itam','view-licenses','view-accessories'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/devices*','admin/printers*','admin/credentials*','admin/employees*','admin/itam*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-cpu me-1"></i>Assets
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega shadow">
                            {{-- ── Device Inventory ── --}}
                            @can('view-assets')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.index') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.index') }}">
                                    <i class="bi bi-cpu me-2"></i>Devices
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.warranty') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.warranty') }}">
                                    <i class="bi bi-shield-exclamation me-2"></i>Warranty Tracker
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.firmware') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.firmware') }}">
                                    <i class="bi bi-arrow-up-circle me-2"></i>Firmware Tracker
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.models.*') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.models.index') }}">
                                    <i class="bi bi-collection me-2"></i>Device Models
                                </a>
                            </li>
                            @endcan
                            @can('manage-assets')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.phone-auto-assign') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.phone-auto-assign') }}">
                                    <i class="bi bi-telephone-plus me-2"></i>Phone Auto-Assign
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.import') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.import') }}">
                                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Import MAC/Serial
                                </a>
                            </li>
                            @endcan
                            @can('view-printers')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.printers.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2 text-warning"></i>Printer Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers') || request()->is('admin/printers/create') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.index') }}">
                                    <i class="bi bi-printer-fill me-2"></i>Printers
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers/snmp-status') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.snmp.status') }}">
                                    <i class="bi bi-activity me-2 text-success"></i>Printer SNMP Status
                                </a>
                            </li>
                            @can('manage-printers')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/intune-groups*') ? 'active' : '' }}"
                                   href="{{ route('admin.intune-groups.index') }}">
                                    <i class="bi bi-collection me-2 text-primary"></i>Intune Groups
                                </a>
                            </li>
                            @endcan
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/network-discovery*') ? 'active' : '' }}"
                                   href="{{ route('admin.network-discovery.index') }}">
                                    <i class="bi bi-radar me-2 text-info"></i>Network Discovery
                                </a>
                            </li>
                            @endcan
                            @can('view-credentials')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/credentials*') ? 'active' : '' }}"
                                   href="{{ route('admin.credentials.index') }}">
                                    <i class="bi bi-key-fill me-2"></i>Credentials
                                </a>
                            </li>
                            @endcan
                            @can('view-employees')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/employees*') ? 'active' : '' }}"
                                   href="{{ route('admin.employees.index') }}">
                                    <i class="bi bi-person-vcard-fill me-2"></i>Employees
                                </a>
                            </li>
                            @endcan

                            {{-- ── ITAM section ── --}}
                            @canany(['view-itam','view-licenses','view-accessories'])
                            <li><hr class="dropdown-divider"></li>
                            @endcanany
                            @can('view-itam')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>ITAM Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.suppliers.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.suppliers.index') }}">
                                    <i class="bi bi-shop me-2"></i>Suppliers
                                </a>
                            </li>
                            @endcan
                            @can('view-licenses')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.licenses.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.licenses.index') }}">
                                    <i class="bi bi-file-earmark-check me-2"></i>Software Licenses
                                </a>
                            </li>
                            @endcan
                            @can('view-accessories')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.accessories.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.accessories.index') }}">
                                    <i class="bi bi-box-seam me-2"></i>Accessories
                                </a>
                            </li>
                            @endcan
                            @can('manage-itam')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.azure.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.azure.index') }}">
                                    <i class="bi bi-microsoft me-2"></i>Azure Device Sync
                                </a>
                            </li>
                            @endcan
                            @can('view-itam')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.mac-address') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.mac-address') }}">
                                    <i class="bi bi-fingerprint me-2"></i>MAC Registry
                                </a>
                            </li>
                            @endcan
                            @can('view-assets')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.scan') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.scan') }}">
                                    <i class="bi bi-qr-code-scan me-2"></i>QR Scanner
                                </a>
                            </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- ── Workflows dropdown ── --}}
                    @canany(['view-workflows','manage-workflows','approve-workflows'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/workflows*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-diagram-2-fill me-1"></i>Workflows
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @can('view-workflows')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.my-requests') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.my-requests') }}">
                                    <i class="bi bi-send me-2"></i>My Requests
                                </a>
                            </li>
                            @endcan
                            @can('approve-workflows')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.pending') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.pending') }}">
                                    <i class="bi bi-clock-fill me-2"></i>Pending Approvals
                                    @php $__pendingCount = \App\Models\WorkflowRequest::where('status','pending')->count(); @endphp
                                    @if($__pendingCount > 0)
                                    <span class="badge bg-danger ms-1">{{ $__pendingCount }}</span>
                                    @endif
                                </a>
                            </li>
                            @endcan
                            @can('view-workflows')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.index') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.index') }}">
                                    <i class="bi bi-list-ul me-2"></i>All Workflows
                                </a>
                            </li>
                            @endcan
                            @can('manage-workflows')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.create') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.create') }}">
                                    <i class="bi bi-plus-circle-fill me-2"></i>New Request
                                </a>
                            </li>
                            @endcan
                            @can('manage-workflow-templates')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflow-templates.index') ? 'active' : '' }}"
                                   href="{{ route('admin.workflow-templates.index') }}">
                                    <i class="bi bi-diagram-3 me-2"></i>Workflow Templates
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/workflow-templates/*/builder') ? 'active' : '' }}"
                                   href="/admin/workflow-templates"
                                   title="Open visual builder from any template row">
                                    <i class="bi bi-node-plus me-2"></i>Visual Builder
                                </a>
                            </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- ── Form Builder dropdown ── --}}
                    @can('manage-workflows')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/forms*') || request()->is('forms*') || request()->is('my/forms*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-ui-checks-grid me-1"></i>Forms
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.forms.index') ? 'active' : '' }}"
                                   href="{{ route('admin.forms.index') }}">
                                    <i class="bi bi-list-ul me-2"></i>All Forms
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.forms.create') ? 'active' : '' }}"
                                   href="{{ route('admin.forms.create') }}">
                                    <i class="bi bi-plus-circle-fill me-2"></i>New Form
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endcan

                    {{-- ── NOC dropdown ── --}}
                    @can('view-noc')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/noc*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-speedometer2 me-1"></i>NOC
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.alerts') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.alerts') }}">
                                    <i class="bi bi-bell-fill me-2"></i>Alert Feed
                                    @php $__openAlerts = \App\Models\NocEvent::where('status','open')->count(); @endphp
                                    @if($__openAlerts > 0)
                                    <span class="badge bg-danger ms-1">{{ $__openAlerts }}</span>
                                    @endif
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.extensions') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.extensions') }}">
                                    <i class="bi bi-telephone-fill me-2"></i>Extension Grid
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.events') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.events') }}">
                                    <i class="bi bi-clock-history me-2"></i>Events Log
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.wallboard') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.wallboard') }}" target="_blank">
                                    <i class="bi bi-display me-2"></i>Wallboard
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            @canany(['view-incidents','manage-incidents'])
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.incidents.*') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.incidents.index') }}">
                                    <i class="bi bi-journal-text me-2"></i>Incidents
                                    @php $__openInc = \App\Models\Incident::whereIn('status',['open','investigating'])->count(); @endphp
                                    @if($__openInc > 0)
                                    <span class="badge bg-danger ms-1">{{ $__openInc }}</span>
                                    @endif
                                </a>
                            </li>
                            @endcanany
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.telnet.*') ? 'active' : '' }}"
                                   href="{{ route('admin.telnet.index') }}">
                                    <i class="bi bi-terminal-fill me-2 text-success"></i>Telnet / SSH Client
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.browser.*') ? 'active' : '' }}"
                                   href="{{ route('admin.browser.index') }}" target="_blank">
                                    <i class="bi bi-globe me-2 text-primary"></i>Web Browser
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.alerts.dashboard') || request()->routeIs('admin.alert-rules.*') ? 'active' : '' }}"
                                   href="{{ route('admin.alerts.dashboard') }}">
                                    <i class="bi bi-shield-exclamation me-2"></i>Alert Rules
                                    @php
                                        try {
                                            $__activeAlerts = \App\Models\AlertState::where('state','alerted')->count();
                                        } catch (\Throwable $e) {
                                            $__activeAlerts = 0;
                                        }
                                    @endphp
                                    @if($__activeAlerts > 0)
                                    <span class="badge bg-danger ms-1">{{ $__activeAlerts }}</span>
                                    @endif
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endcan

                    {{-- ── IT Tasks ── --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/tasks*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-list-task me-1"></i>Tasks
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.tasks.index') ? 'active' : '' }}"
                                   href="{{ route('admin.tasks.index') }}">
                                    <i class="bi bi-table me-2"></i>All Tasks
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.tasks.my-tasks') ? 'active' : '' }}"
                                   href="{{ route('admin.tasks.my-tasks') }}">
                                    <i class="bi bi-person-check me-2"></i>My Tasks
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.tasks.kanban') ? 'active' : '' }}"
                                   href="{{ route('admin.tasks.kanban') }}">
                                    <i class="bi bi-kanban me-2"></i>Kanban Board
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.tasks.create') ? 'active' : '' }}"
                                   href="{{ route('admin.tasks.create') }}">
                                    <i class="bi bi-plus-circle me-2"></i>New Task
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- ── Identity dropdown ── --}}
                    @can('view-identity')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/identity*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-people-fill me-1"></i>Identity
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.users') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.users') }}">
                                    <i class="bi bi-people me-2"></i>Users
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.licenses') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.licenses') }}">
                                    <i class="bi bi-patch-check me-2"></i>Licenses
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.groups') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.groups') }}">
                                    <i class="bi bi-collection me-2"></i>Groups
                                </a>
                            </li>
                            @can('manage-identity')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/identity/group-mappings*') ? 'active' : '' }}"
                                   href="/admin/identity/group-mappings">
                                    <i class="bi bi-diagram-3 me-2"></i>Group Auto-Assignments
                                </a>
                            </li>
                            @endcan
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.sync-logs') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.sync-logs') }}">
                                    <i class="bi bi-clock-history me-2"></i>Sync Logs
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endcan

                    {{-- ── My Printers (SSO auto-assign) ── --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/my-printers*') ? 'active' : '' }}"
                           href="/admin/my-printers">
                            <i class="bi bi-printer-fill me-1"></i>My Printers
                        </a>
                    </li>

                    {{-- ── Documentation ── --}}
                    @can('view-documentation')
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/documentation*') ? 'active' : '' }}"
                           href="{{ route('admin.documentation.index') }}">
                            <i class="bi bi-book-fill me-1"></i>Documentation
                        </a>
                    </li>
                    @endcan

                    {{-- ── Admin Tools ── --}}
                    @can('view-admin-links')
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/admin-links*') ? 'active' : '' }}"
                           href="{{ route('admin.admin-links.index') }}">
                            <i class="bi bi-grid-3x3-gap-fill me-1"></i>Admin Tools
                        </a>
                    </li>
                    @endcan

                    {{-- ── Settings dropdown ── --}}
                    @canany(['manage-settings','manage-users','manage-permissions','view-phone-logs','view-activity-logs','manage-notification-rules','view-email-logs','manage-license-monitors','manage-allowed-domains'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/settings*','admin/users*','admin/permissions*','admin/phone-logs*','admin/activity-logs*','admin/branches*','admin/notifications*','admin/license-monitors*','admin/internet-access-levels*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i>Settings
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega shadow">
                            @can('manage-settings')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.index') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.index') }}">
                                    <i class="bi bi-sliders me-2"></i>General Settings
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-building me-1"></i>Organisation</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.locations') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.locations') }}">
                                    <i class="bi bi-geo-alt-fill me-2"></i>Locations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/branches*') ? 'active' : '' }}"
                                   href="{{ route('admin.branches.index') }}">
                                    <i class="bi bi-building me-2"></i>Branches
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.departments') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.departments') }}">
                                    <i class="bi bi-grid-1x2-fill me-2"></i>Departments
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.domains') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.domains') }}">
                                    <i class="bi bi-globe me-2"></i>Allowed Domains
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.asset-types') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.asset-types') }}">
                                    <i class="bi bi-tags-fill me-2"></i>Asset Types & Codes
                                </a>
                            </li>
                            {{-- ── Internet Access Levels (guarded until route is registered) ── --}}
                            @if (Route::has('admin.settings.internet-access-levels.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.internet-access-levels.*') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.internet-access-levels.index') }}">
                                    <i class="bi bi-wifi me-2"></i>Internet Access Levels
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-cloud-check me-1"></i>Provisioning</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.provisioning-licenses') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.provisioning-licenses') }}">
                                    <i class="bi bi-patch-check-fill me-2"></i>Provisioning Licenses
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/api-docs*') ? 'active' : '' }}"
                                   href="/admin/api-docs">
                                    <i class="bi bi-code-slash me-2"></i>HR API Docs & Keys
                                </a>
                            </li>
                            @can('manage-settings')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.hr-api-keys.*') ? 'active' : '' }}"
                                   href="/admin/hr-api-keys">
                                    <i class="bi bi-key me-2"></i>HR API Keys
                                </a>
                            </li>
                            @endcan
                            <li><hr class="dropdown-divider"></li>
                            @endcan
                            @can('manage-users')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/users*') ? 'active' : '' }}"
                                   href="{{ route('admin.users.index') }}">
                                    <i class="bi bi-person-badge-fill me-2"></i>Users
                                </a>
                            </li>
                            @endcan
                            @can('manage-permissions')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/permissions*') ? 'active' : '' }}"
                                   href="{{ route('admin.permissions.index') }}">
                                    <i class="bi bi-shield-lock-fill me-2"></i>Permissions
                                </a>
                            </li>
                            @endcan
                            @canany(['manage-notification-rules','view-email-logs','manage-license-monitors','manage-allowed-domains'])
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-layers me-1"></i>Platform</h6></li>
                            @can('manage-notification-rules')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.notification-rules.index') ? 'active' : '' }}"
                                   href="{{ route('admin.notification-rules.index') }}">
                                    <i class="bi bi-funnel-fill me-2"></i>Notification Rules
                                </a>
                            </li>
                            @endcan
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.sync-status') ? 'active' : '' }}"
                                   href="{{ route('admin.sync-status') }}">
                                    <i class="bi bi-arrow-repeat me-2"></i>Sync Status
                                </a>
                            </li>
                            @can('view-email-logs')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-log.index') ? 'active' : '' }}"
                                   href="{{ route('admin.email-log.index') }}">
                                    <i class="bi bi-envelope-check me-2"></i>Email Log
                                </a>
                            </li>
                            @endcan
                            @can('manage-license-monitors')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.license-monitors.index') ? 'active' : '' }}"
                                   href="{{ route('admin.license-monitors.index') }}">
                                    <i class="bi bi-clipboard2-pulse me-2"></i>License Monitors
                                </a>
                            </li>
                            @endcan
                            @endcanany
                            @canany(['view-phone-logs','view-activity-logs'])
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-journal-text me-1"></i>Logs</h6></li>
                            @can('view-phone-logs')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/phone-logs*') ? 'active' : '' }}"
                                   href="{{ route('admin.phone-logs.index') }}">
                                    <i class="bi bi-telephone-inbound-fill me-2"></i>Phone Logs
                                </a>
                            </li>
                            @endcan
                            @can('view-activity-logs')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/activity-logs*') ? 'active' : '' }}"
                                   href="{{ route('admin.activity-logs') }}">
                                    <i class="bi bi-shield-check me-2"></i>Audit Log
                                </a>
                            </li>
                            @endcan
                            @endcanany
                        </ul>
                    </li>
                    @endcanany

                </ul>

                {{-- ── Notification Bell ── --}}
                <ul class="navbar-nav ms-2">
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative px-2" href="#"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false"
                           id="notifBell">
                            <i class="bi bi-bell-fill fs-5"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                                  id="notifBadge" style="font-size:10px"></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:320px;max-width:380px" id="notifDropdown">
                            <li class="px-3 py-2 d-flex justify-content-between align-items-center">
                                <strong class="small">Notifications</strong>
                                <form method="POST" action="{{ route('admin.notifications.read-all') }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-muted small">Mark all read</button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li id="notifItems">
                                <div class="px-3 py-3 text-center text-muted small" id="notifEmpty">
                                    <i class="bi bi-bell-slash me-1"></i>No new notifications
                                </div>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item text-center small" href="{{ route('admin.notifications.index') }}">
                                    <i class="bi bi-list-ul me-1"></i>View All Notifications
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>

                {{-- ── Dark Mode Toggle ── --}}
                <ul class="navbar-nav ms-2">
                    <li class="nav-item d-flex align-items-center">
                        <button type="button"
                                class="btn btn-link nav-link dark-mode-toggle px-2"
                                title="Toggle dark mode"
                                @click="
                                    dark = !dark;
                                    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
                                    fetch('{{ route('admin.toggle-dark-mode') }}', {
                                        method: 'POST',
                                        headers: {
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                            'Accept': 'application/json'
                                        }
                                    });
                                ">
                            <i class="bi" :class="dark ? 'bi-sun' : 'bi-moon-stars'"></i>
                        </button>
                    </li>
                </ul>

                {{-- ── Profile dropdown ── --}}
                <ul class="navbar-nav ms-2">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 py-1" href="#"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="avatar-circle">
                                {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                            </span>
                            <span class="d-none d-lg-inline">{{ auth()->user()->name }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <span class="dropdown-item-text small">
                                    <div class="fw-semibold">{{ auth()->user()->name }}</div>
                                    <div class="text-muted">{{ auth()->user()->email }}</div>
                                    <span class="badge bg-secondary mt-1">
                                        {{ \App\Models\User::roleLabel(auth()->user()->role ?? 'admin') }}
                                    </span>
                                </span>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="#"
                                   data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                    <i class="bi bi-key me-2"></i>Change Password
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="{{ route('admin.two-factor.setup') }}">
                                    <i class="bi bi-shield-lock me-2"></i>Two-Factor Auth
                                    @if(auth()->user()->hasTwoFactorEnabled())
                                        <span class="badge bg-success ms-1" style="font-size:0.65rem;">ON</span>
                                    @endif
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="/logout">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>

            </div>
        </div>
    </nav>

    <!-- PAGE CONTENT -->
    <div class="container-fluid px-3 px-lg-4 mt-4 mb-5">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @yield('content')
    </div>

    {{-- ── Change Password Modal (global, available on every admin page) ── --}}
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.profile.password') }}">
                    @csrf @method('PUT')
                    <div class="modal-header bg-secondary text-white">
                        <h5 class="modal-title"><i class="bi bi-key me-2"></i>Change Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    @stack('scripts')

    {{-- ApexCharts global defaults (available to any page that loads apexcharts.js) --}}
    <script>
    window.Apex = {
        chart: {
            fontFamily: 'inherit',
            toolbar: { show: false },
            zoom:    { enabled: false },
            animations: { easing: 'easeinout', speed: 400 }
        },
        grid: {
            borderColor: '#e9ecef',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } }
        },
        stroke:  { width: 2, curve: 'smooth' },
        tooltip: { theme: 'light', x: { format: 'dd MMM HH:mm' } },
        xaxis:   {
            type: 'datetime',
            labels: { datetimeUTC: false, style: { colors: '#6c757d', fontSize: '11px' } },
            axisBorder: { show: false },
            axisTicks:  { show: false }
        },
        yaxis:   { labels: { style: { colors: '#6c757d', fontSize: '11px' } } },
        legend:  { position: 'top', horizontalAlign: 'left', fontSize: '12px', markers: { radius: 3 } },
        colors:  ['#0d6efd','#dc3545','#198754','#ffc107','#0dcaf0','#6f42c1'],
        fill:    { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
        dataLabels: { enabled: false },
        noData:  { text: 'No data available', style: { color: '#adb5bd', fontSize: '13px' } }
    };
    </script>

    {{-- Notification Bell Polling --}}
    <script>
    (function () {
        const bell    = document.getElementById('notifBell');
        const badge   = document.getElementById('notifBadge');
        const items   = document.getElementById('notifItems');
        const empty   = document.getElementById('notifEmpty');

        function severityBorder(s) {
            return s === 'critical' ? '#dc3545' : (s === 'warning' ? '#ffc107' : '#0dcaf0');
        }

        function loadNotifications() {
            fetch('{{ route('admin.notifications.unread-count') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                const count = data.count ?? 0;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }

                // Render latest unread items
                if (data.items && data.items.length > 0) {
                    let html = '';
                    data.items.forEach(n => {
                        html += `<li>
                            <a href="${n.link || '#'}" class="dropdown-item py-2 px-3 small"
                               style="border-left:3px solid ${severityBorder(n.severity)};white-space:normal">
                                <div class="fw-semibold">${n.title}</div>
                                <div class="text-muted" style="font-size:11px">${n.created_at}</div>
                            </a>
                        </li>`;
                    });
                    items.innerHTML = html;
                } else {
                    items.innerHTML = '<div class="px-3 py-3 text-center text-muted small"><i class="bi bi-bell-slash me-1"></i>No new notifications</div>';
                }
            })
            .catch(() => {});
        }

        // Load once on page load and then every 60 seconds
        loadNotifications();
        setInterval(loadNotifications, 60000);
    })();
    </script>

    {{-- Global Modal Form Debounce (Double-Click Protection) --}}
    <script>
    document.addEventListener('submit', function (e) {
        const modal = e.target.closest('.modal');
        if (modal) {
            const form = e.target;
            const submitBtn = form.querySelector('[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                const originalHtml = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

                // If the form fails validation or doesn't cause a page reload,
                // we want a safety to re-enable it (though typically Laravel redirects/reloads)
                window.addEventListener('pageshow', function() {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalHtml;
                });
            }
        }
    });
    </script>

</body>
</html>
