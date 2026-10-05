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
    {{-- Tailwind utilities (preflight, container, visibility core plugins disabled in
         tailwind.config.js so this can coexist with Bootstrap). Required for the
         welcome screen's Tailwind classes to render correctly. --}}
    @vite(['resources/css/app.css'])
    @stack('head')

    {{-- Defensive override: force Bootstrap's .navbar-collapse to display even if
         a stale Tailwind build still ships .collapse/.visible utilities that would
         apply visibility:collapse to the navbar and hide every dropdown.
         Loaded AFTER @vite so it always wins. --}}
    <style>
        nav.navbar.navbar-expand-lg .navbar-collapse {
            visibility: visible !important;
        }
        @media (min-width: 992px) {
            nav.navbar.navbar-expand-lg .navbar-collapse {
                display: flex !important;
                flex-basis: auto !important;
            }
        }
        nav.navbar .navbar-toggler {
            visibility: visible !important;
        }
    </style>

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
            .dropdown-menu.dropdown-mega-3 {
                min-width: 660px;
                columns: 3;
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

                    {{-- ── NOC dropdown (ops landing — first) ── --}}
                    @canroute('admin.noc.dashboard', 'admin.logs.branches.index', 'admin.snmp-devices.index', 'admin.branch-agents.index', 'admin.noc.incidents.index', 'admin.deploy.index', 'portal.browser', 'admin.browser-portal.index', 'admin.alerts.dashboard')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/noc*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-speedometer2 me-1"></i>NOC
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.noc.dashboard', 'admin.noc.health', 'admin.noc.overview.index', 'admin.noc.alerts', 'admin.noc.extensions', 'admin.noc.events')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.health') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.health') }}">
                                    <i class="bi bi-activity me-2"></i>Branch Health Index
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.overview.*') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.overview.index') }}">
                                    <i class="bi bi-grid-1x2-fill me-2"></i>Overview
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
                            @endcanroute
                            @canroute('admin.logs.branches.index', 'admin.logs.branches.sophos', 'admin.logs.branches.ucm')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.logs.branches.index') ? 'active' : '' }}"
                                   href="{{ route('admin.logs.branches.index') }}">
                                    <i class="bi bi-diagram-3 me-2 text-success"></i>Branch Logs
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.logs.branches.sophos') ? 'active' : '' }}"
                                   href="{{ route('admin.logs.branches.sophos') }}">
                                    <i class="bi bi-shield-shaded me-2 text-danger"></i>Branch Logs · Sophos
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.logs.branches.ucm') ? 'active' : '' }}"
                                   href="{{ route('admin.logs.branches.ucm') }}">
                                    <i class="bi bi-telephone-fill me-2 text-warning"></i>Branch Logs · UCM (by IP)
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.branches.log-collectors.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.branches.log-collectors.*') ? 'active' : '' }}"
                                   href="{{ route('admin.branches.log-collectors.index') }}">
                                    <i class="bi bi-hdd-network me-2 text-secondary"></i>Branch Log Collectors
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.branch-agents.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.branch-agents.*') ? 'active' : '' }}"
                                   href="{{ route('admin.branch-agents.index') }}">
                                    <i class="bi bi-pc-display-horizontal me-2 text-info"></i>Branch Agents
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.snmp-devices.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.snmp-devices.*') ? 'active' : '' }}"
                                   href="{{ route('admin.snmp-devices.index') }}">
                                    <i class="bi bi-router me-2 text-primary"></i>SNMP Devices
                                </a>
                            </li>
                            @if(config('services.grafana.url'))
                            <li>
                                <a class="dropdown-item"
                                   href="{{ config('services.grafana.url') }}"
                                   target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-graph-up me-2 text-success"></i>Metrics (Grafana)
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            @endif
                            @endcanroute
                            @canroute('admin.noc.wallboard')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.noc.wallboard') ? 'active' : '' }}"
                                   href="{{ route('admin.noc.wallboard') }}" target="_blank">
                                    <i class="bi bi-display me-2"></i>Wallboard
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.noc.incidents.index')
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
                            @endcanroute
                            @canroute('admin.telnet.index', 'admin.deploy.index', 'portal.browser', 'admin.browser-portal.index')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.telnet.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.telnet.*') ? 'active' : '' }}"
                                   href="{{ route('admin.telnet.index') }}">
                                    <i class="bi bi-terminal-fill me-2 text-success"></i>Telnet / SSH Client
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.deploy.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.deploy.*') ? 'active' : '' }}"
                                   href="{{ route('admin.deploy.index') }}">
                                    <i class="bi bi-rocket-takeoff-fill me-2 text-primary"></i>Deployment Servers
                                </a>
                            </li>
                            @endcanroute
                            @canroute('portal.browser')
                            <li>
                                <a class="dropdown-item" href="{{ route('portal.browser') }}" target="_blank">
                                    <i class="bi bi-shield-lock me-2 text-warning"></i>Remote Browser (Portal)
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.browser-portal.index', 'admin.browser-portal.events', 'admin.browser-portal.settings')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.browser-portal.index') || request()->routeIs('admin.browser-portal.logs') ? 'active' : '' }}"
                                   href="{{ route('admin.browser-portal.index') }}">
                                    <i class="bi bi-people-fill me-2 text-info"></i>Browser — Active Sessions
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.browser-portal.events') ? 'active' : '' }}"
                                   href="{{ route('admin.browser-portal.events') }}">
                                    <i class="bi bi-journal-text me-2 text-muted"></i>Browser — Activity Log
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.browser-portal.settings') ? 'active' : '' }}"
                                   href="{{ route('admin.browser-portal.settings') }}">
                                    <i class="bi bi-gear me-2 text-muted"></i>Browser — Settings
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.alerts.dashboard')
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
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Telephony dropdown (Contacts + UCM/Extensions + Call Quality) ── --}}
                    @canroute('admin.contacts.index', 'admin.extensions.index', 'admin.trunks.index', 'admin.phones.index', 'admin.phones.firmware.index', 'admin.voice-quality.dashboard')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/contacts*','admin/extensions*','admin/trunks*','admin/gdms*','admin/phones*','admin/voice-quality*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-telephone-fill me-1"></i>Telephony
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.contacts.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/contacts*') ? 'active' : '' }}"
                                   href="{{ route('admin.contacts.index') }}">
                                    <i class="bi bi-person-lines-fill me-2"></i>Contacts
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.extensions.index', 'admin.trunks.index', 'admin.gdms.ucm', 'admin.phones.index', 'admin.phones.firmware.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-hdd-stack me-1"></i>UCM / PBX</h6></li>
                            @endcanroute
                            @canroute('admin.extensions.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/extensions*') ? 'active' : '' }}"
                                   href="{{ route('admin.extensions.index') }}">
                                    <i class="bi bi-telephone me-2"></i>Extensions
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.trunks.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/trunks*') ? 'active' : '' }}"
                                   href="{{ route('admin.trunks.index') }}">
                                    <i class="bi bi-hdd-network-fill me-2"></i>Trunks
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.gdms.ucm')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/gdms*') ? 'active' : '' }}"
                                   href="{{ route('admin.gdms.ucm') }}">
                                    <i class="bi bi-cloud-check-fill me-2"></i>UCM Status
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.phones.index', 'admin.gdms.templates.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.phones.index', 'admin.phones.show', 'admin.phones.create') ? 'active' : '' }}"
                                   href="{{ route('admin.phones.index') }}">
                                    <i class="bi bi-telephone-plus me-2"></i>Phones
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/gdms/templates*') ? 'active' : '' }}"
                                   href="{{ route('admin.gdms.templates.index') }}">
                                    <i class="bi bi-file-earmark-code me-2"></i>Config Templates
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.phones.firmware.index', 'admin.phones.firmware.status', 'admin.phones.firmware.downloads')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/phones/firmware') ? 'active' : '' }}"
                                   href="{{ route('admin.phones.firmware.index') }}">
                                    <i class="bi bi-hdd-network me-2"></i>Firmware Server
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/phones/firmware/status') ? 'active' : '' }}"
                                   href="{{ route('admin.phones.firmware.status') }}">
                                    <i class="bi bi-clipboard-check me-2"></i>Firmware Status
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/phones/firmware/downloads') ? 'active' : '' }}"
                                   href="{{ route('admin.phones.firmware.downloads') }}">
                                    <i class="bi bi-download me-2"></i>Firmware Downloads
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.telecom.landlines.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-telephone-inbound me-1"></i>Telecom</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/telecom/landlines*') ? 'active' : '' }}"
                                   href="{{ route('admin.telecom.landlines.index') }}">
                                    <i class="bi bi-telephone me-2"></i>Landlines
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.voice-quality.dashboard')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-soundwave me-1"></i>Call Quality</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.voice-quality.*') ? 'active' : '' }}"
                                   href="{{ route('admin.voice-quality.dashboard') }}">
                                    <i class="bi bi-soundwave me-2 text-info"></i>Voice Quality
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Attendance dropdown (with Vacations) ── --}}
                    @canroute('admin.people.index', 'admin.attendance.sources.index', 'admin.vacations.imports.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/attendance*') || request()->is('admin/vacations*') || request()->is('admin/people*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-fingerprint me-1"></i>Attendance
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.people.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.people.*') && ! request()->routeIs('admin.people.breakdown', 'admin.people.saudization*') ? 'active' : '' }}"
                                   href="{{ route('admin.people.index') }}">
                                    <i class="bi bi-person-badge me-2"></i>Employee Profiles
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.people.breakdown') ? 'active' : '' }}"
                                   href="{{ route('admin.people.breakdown') }}">
                                    <i class="bi bi-pie-chart me-2"></i>Workforce Breakdown
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.people.saudization*') ? 'active' : '' }}"
                                   href="{{ route('admin.people.saudization') }}">
                                    <i class="bi bi-flag me-2"></i>Saudization
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.attendance.days.index', 'admin.attendance.employees.index', 'admin.attendance.areas.index', 'admin.attendance.shifts.index', 'admin.attendance.holidays.index', 'admin.attendance.periods.index', 'admin.attendance.owners.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.days.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.days.index') }}">
                                    <i class="bi bi-calendar-check me-2"></i>Check-in / Check-out
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.employees.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.employees.index') }}">
                                    <i class="bi bi-person-lines-fill me-2"></i>Employee Mapping
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.areas.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.areas.index') }}">
                                    <i class="bi bi-geo-alt me-2"></i>Areas &amp; Terminals
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.shifts.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.shifts.index') }}">
                                    <i class="bi bi-clock-history me-2"></i>Shifts
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.holidays.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.holidays.index') }}">
                                    <i class="bi bi-calendar-heart me-2"></i>Holidays
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.periods.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.periods.index') }}">
                                    <i class="bi bi-journal-check me-2"></i>Periods
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.owners.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.owners.index') }}">
                                    <i class="bi bi-shield-lock me-2"></i>Owners
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.attendance.sources.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.attendance.sources.*') ? 'active' : '' }}"
                                   href="{{ route('admin.attendance.sources.index') }}">
                                    <i class="bi bi-database-gear me-2"></i>BioTime Sources
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.vacations.balances.index', 'admin.vacations.absences.index', 'admin.vacations.imports.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-airplane me-1"></i>Vacations</h6></li>
                            @endcanroute
                            @canroute('admin.vacations.balances.index', 'admin.vacations.absences.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.vacations.balances.*') ? 'active' : '' }}"
                                   href="{{ route('admin.vacations.balances.index') }}">
                                    <i class="bi bi-wallet2 me-2"></i>Vacation Balances
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.vacations.absences.*') ? 'active' : '' }}"
                                   href="{{ route('admin.vacations.absences.index') }}">
                                    <i class="bi bi-calendar-range me-2"></i>Leave Records
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.vacations.imports.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.vacations.imports.*') ? 'active' : '' }}"
                                   href="{{ route('admin.vacations.imports.index') }}">
                                    <i class="bi bi-file-earmark-arrow-up me-2"></i>Import from Oracle
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Network dropdown (with Printers) ── --}}
                    @canroute('admin.network.overview', 'admin.network.voice-mesh.index', 'admin.network.monitoring.index', 'admin.switch-qos.dashboard', 'admin.printers.index', 'admin.network.dhcp.index', 'admin.network.sophos.index', 'admin.network.fortigate.index', 'admin.network.access-points.index', 'admin.backups.index', 'admin.downloads.index', 'admin.radius.macs.index', 'admin.network.dns.index', 'admin.network.events', 'admin.printers.usage', 'admin.printers.branch.index', 'admin.intune-groups.index', 'admin.print-manager.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/network*','admin/printers*','admin/print-manager*','admin/my-printers*','admin/intune-groups*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-diagram-3-fill me-1"></i>Network
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega dropdown-mega-3 shadow">
                            @canroute('admin.network.overview', 'admin.network.tunnel-health.index', 'admin.network.tunnel-health.history', 'admin.network.tunnel-health.report')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.overview') ? 'active' : '' }}"
                                   href="{{ route('admin.network.overview') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>Meraki Overview
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.tunnel-health.index') ? 'active' : '' }}"
                                   href="{{ route('admin.network.tunnel-health.index') }}">
                                    <i class="bi bi-shield-lock me-2"></i>Branch Tunnel Watchdog
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.tunnel-health.history') ? 'active' : '' }}"
                                   href="{{ route('admin.network.tunnel-health.history') }}">
                                    <i class="bi bi-calendar-week me-2"></i>Tunnel History (7 days)
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.tunnel-health.report*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.tunnel-health.report') }}">
                                    <i class="bi bi-file-earmark-text me-2"></i>Tunnel Outage Report
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.voice-mesh.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.voice-mesh.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.voice-mesh.index') }}">
                                    <i class="bi bi-telephone-outbound me-2"></i>Voice Mesh
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.isp.index', 'admin.network.isp-providers.index', 'admin.network.isp-report.index', 'admin.network.ip-reservations.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.isp.*') && ! request()->routeIs('admin.network.isp-report.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.isp.index') }}">
                                    <i class="bi bi-globe2 me-2"></i>ISP Connections
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.isp-providers.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.isp-providers.index') }}">
                                    <i class="bi bi-building me-2"></i>ISP Providers
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.isp-report.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.isp-report.index') }}">
                                    <i class="bi bi-clipboard-data me-2"></i>ISP Report
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.ip-reservations.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.ip-reservations.index') }}">
                                    <i class="bi bi-hdd-rack me-2"></i>IP Reservations
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.diagnostics.index', 'admin.network.monitoring.index', 'admin.network.monitoring.hosts.list', 'admin.network.monitoring.dashboard')
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
                            @endcanroute
                            @canroute('admin.switch-qos.dashboard')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.switch-qos.*') ? 'active' : '' }}"
                                   href="{{ route('admin.switch-qos.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2 text-primary"></i>Switch QoS Monitor
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.workers.index', 'admin.network.scanner.index')
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
                            @endcanroute
                            @canroute('admin.network-discovery.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/network-discovery*') ? 'active' : '' }}"
                                   href="{{ route('admin.network-discovery.index') }}">
                                    <i class="bi bi-broadcast-pin me-2"></i>Network Discovery
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.sla.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.sla.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.sla.index') }}">
                                    <i class="bi bi-graph-up me-2"></i>SLA Dashboard
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.ipam.index', 'admin.network.dhcp.index', 'admin.network.sophos.index', 'admin.network.fortigate.index', 'admin.network.access-points.index', 'admin.backups.index', 'admin.downloads.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-hdd-rack me-1"></i>IPAM / DHCP</h6></li>
                            @endcanroute
                            @canroute('admin.network.ipam.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.ipam.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.ipam.index') }}">
                                    <i class="bi bi-grid-3x3 me-2"></i>IPAM Subnets
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.dhcp.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.dhcp.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.dhcp.index') }}">
                                    <i class="bi bi-hdd-network-fill me-2"></i>DHCP Leases
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.sophos.index', 'admin.network.sophos-central.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.sophos.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.sophos.index') }}">
                                    <i class="bi bi-shield-fill me-2"></i>Sophos Firewalls
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.sophos-central.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.sophos-central.index') }}">
                                    <i class="bi bi-cloud-fill me-2"></i>Sophos Central
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.fortigate.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.fortigate.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.fortigate.index') }}">
                                    <i class="bi bi-bricks me-2"></i>FortiGate Firewalls
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.access-points.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.access-points.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.access-points.index') }}">
                                    <i class="bi bi-router me-2"></i>Access Points
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.backups.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.backups.*') ? 'active' : '' }}"
                                   href="{{ route('admin.backups.index') }}">
                                    <i class="bi bi-shield-lock-fill me-2"></i>Device Backups
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.downloads.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.downloads.*') ? 'active' : '' }}"
                                   href="{{ route('admin.downloads.index') }}">
                                    <i class="bi bi-cloud-arrow-up-fill me-2"></i>Download Center
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.radius.macs.index', 'admin.radius.nas.index', 'admin.radius.vlan.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-shield-lock me-1"></i>RADIUS / 802.1X</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.radius.macs.*') ? 'active' : '' }}"
                                   href="{{ route('admin.radius.macs.index') }}">
                                    <i class="bi bi-fingerprint me-2"></i>MAC Registry
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.radius.nas.*') ? 'active' : '' }}"
                                   href="{{ route('admin.radius.nas.index') }}">
                                    <i class="bi bi-router me-2"></i>NAS Clients
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.radius.vlan.*') ? 'active' : '' }}"
                                   href="{{ route('admin.radius.vlan.index') }}">
                                    <i class="bi bi-diagram-3 me-2"></i>VLAN Policy
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.dns.index', 'admin.network.dns.lookup.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-globe2 me-1"></i>DNS</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.dns.*') && !request()->routeIs('admin.network.dns.lookup.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.dns.index') }}">
                                    <i class="bi bi-globe2 me-2"></i>DNS Accounts
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.dns.lookup.*') ? 'active' : '' }}"
                                   href="{{ route('admin.network.dns.lookup.index') }}">
                                    <i class="bi bi-search me-2"></i>Domain Lookup
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.network.topology.index', 'admin.network.port-map.index', 'admin.network.switches', 'admin.network.clients')
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
                            @endcanroute
                            @canroute('admin.network.events')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.network.events') ? 'active' : '' }}"
                                   href="{{ route('admin.network.events') }}">
                                    <i class="bi bi-clock-history me-2"></i>Change Monitor
                                </a>
                            </li>
                            @endcanroute
                            {{-- ── Printers (was its own top-level menu) ── --}}
                            @canroute('admin.network.overview')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-printer-fill me-1"></i>Printers</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/my-printers*') ? 'active' : '' }}"
                                   href="{{ route('admin.my-printers') }}">
                                    <i class="bi bi-person-badge me-2"></i>My Printers
                                </a>
                            </li>
                            @canroute('admin.printers.dashboard', 'admin.printers.index', 'admin.printers.snmp.status', 'admin.printers.unified.index', 'admin.printers.drivers.index')
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
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers/unified*') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.unified.index') }}">
                                    <i class="bi bi-collection me-2 text-primary"></i>Unified Printers
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers/drivers*') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.drivers.index') }}">
                                    <i class="bi bi-file-earmark-arrow-down me-2"></i>Printer Drivers
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.printers.usage')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers/usage*') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.usage') }}">
                                    <i class="bi bi-bar-chart-fill me-2 text-info"></i>Printer Usage Report
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.printers.branch.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/printers/branch-settings*') ? 'active' : '' }}"
                                   href="{{ route('admin.printers.branch.index') }}">
                                    <i class="bi bi-bell-fill me-2 text-warning"></i>Printer Alert Settings
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.intune-groups.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/intune-groups*') ? 'active' : '' }}"
                                   href="{{ route('admin.intune-groups.index') }}">
                                    <i class="bi bi-collection me-2 text-primary"></i>Intune Groups
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.print-manager.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-cloud-arrow-up me-1"></i>CUPS / IPP Proxy</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/print-manager*') ? 'active' : '' }}"
                                   href="{{ route('admin.print-manager.index') }}">
                                    <i class="bi bi-printer me-2 text-info"></i>Print Manager
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Assets + ITAM dropdown ── --}}
                    @canroute('admin.devices.index', 'admin.credentials.index', 'admin.employees.index', 'admin.itam.dashboard', 'admin.itam.licenses.index', 'admin.itam.accessories.index', 'admin.wallpapers.index', 'admin.itam.transfer.index', 'admin.itam.scrap.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/devices*','admin/credentials*','admin/employees*','admin/itam*','admin/wallpapers*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-cpu me-1"></i>Assets
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega shadow">
                            {{-- ── Device Inventory ── --}}
                            @canroute('admin.devices.index', 'admin.devices.warranty', 'admin.devices.firmware', 'admin.devices.models.index')
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
                            @endcanroute
                            @canroute('admin.devices.phone-auto-assign', 'admin.devices.import')
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
                            @endcanroute
                            {{-- Printer pages live in the Network menu --}}
                            @canroute('admin.credentials.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/credentials*') ? 'active' : '' }}"
                                   href="{{ route('admin.credentials.index') }}">
                                    <i class="bi bi-key-fill me-2"></i>Credentials
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.employees.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/employees*') ? 'active' : '' }}"
                                   href="{{ route('admin.employees.index') }}">
                                    <i class="bi bi-person-vcard-fill me-2"></i>Employees
                                </a>
                            </li>
                            @endcanroute

                            {{-- ── ITAM section ── --}}
                            @canroute('admin.itam.dashboard', 'admin.itam.licenses.index', 'admin.itam.accessories.index', 'admin.wallpapers.index')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.itam.dashboard', 'admin.itam.purchase-orders.index', 'admin.itam.suppliers.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.dashboard') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.dashboard') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>ITAM Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.purchase-orders.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.purchase-orders.index') }}">
                                    <i class="bi bi-receipt me-2"></i>Purchase Orders
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.suppliers.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.suppliers.index') }}">
                                    <i class="bi bi-shop me-2"></i>Suppliers
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.licenses.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.licenses.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.licenses.index') }}">
                                    <i class="bi bi-file-earmark-check me-2"></i>Software Licenses
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.accessories.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.accessories.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.accessories.index') }}">
                                    <i class="bi bi-box-seam me-2"></i>Accessories
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.azure.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.azure.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.azure.index') }}">
                                    <i class="bi bi-microsoft me-2"></i>Azure Device Sync
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.oracle-assets.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.oracle-assets.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.oracle-assets.index') }}">
                                    <i class="bi bi-journal-check me-2"></i>Oracle Asset Register
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.wallpapers.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/wallpapers*') ? 'active' : '' }}"
                                   href="{{ route('admin.wallpapers.index') }}">
                                    <i class="bi bi-image me-2"></i>Managed Wallpapers
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.mac-address')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.mac-address') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.mac-address') }}">
                                    <i class="bi bi-fingerprint me-2"></i>MAC Registry
                                </a>
                            </li>
                            @endcanroute

                            {{-- ── Asset Operations: Transfer / Stores / Scrap / Reports ── --}}
                            @canroute('admin.itam.transfer.index', 'admin.itam.stores.index', 'admin.itam.scrap.index', 'admin.itam.reports.index')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.itam.transfer.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.transfer.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.transfer.index') }}">
                                    <i class="bi bi-arrow-left-right me-2"></i>Asset Transfer
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.stores.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.stores.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.stores.index') }}">
                                    <i class="bi bi-box-seam me-2"></i>Branch Stores
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.scrap.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.scrap.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.scrap.index') }}">
                                    <i class="bi bi-trash3 me-2"></i>Scrap Requests
                                    @php $__scrapPending = \App\Models\WorkflowRequest::where('type','asset_scrap')->where('status','pending')->count(); @endphp
                                    @if($__scrapPending > 0)
                                    <span class="badge bg-warning text-dark ms-1">{{ $__scrapPending }}</span>
                                    @endif
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.itam.reports.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.itam.reports.*') ? 'active' : '' }}"
                                   href="{{ route('admin.itam.reports.index') }}">
                                    <i class="bi bi-file-earmark-bar-graph me-2"></i>Asset Reports
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.devices.scan')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.devices.scan') ? 'active' : '' }}"
                                   href="{{ route('admin.devices.scan') }}">
                                    <i class="bi bi-qr-code-scan me-2"></i>QR Scanner
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Workflows dropdown ── --}}
                    @canroute('admin.workflows.my-requests', 'admin.workflows.pending', 'admin.workflows.create', 'admin.offboarding.index', 'admin.avepoint.dashboard', 'admin.workflow-templates.index', 'admin.forms.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/workflows*','admin/forms*','admin/workflow-templates*','admin/form-previews*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-diagram-2-fill me-1"></i>Workflows
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.workflows.my-requests')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.my-requests') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.my-requests') }}">
                                    <i class="bi bi-send me-2"></i>My Requests
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.workflows.pending')
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
                            @endcanroute
                            @canroute('admin.workflows.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.index') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.index') }}">
                                    <i class="bi bi-list-ul me-2"></i>All Workflows
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.workflows.create')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflows.create') ? 'active' : '' }}"
                                   href="{{ route('admin.workflows.create') }}">
                                    <i class="bi bi-plus-circle-fill me-2"></i>New Request
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.offboarding.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.offboarding.*') ? 'active' : '' }}"
                                   href="{{ route('admin.offboarding.index') }}">
                                    <i class="bi bi-person-x-fill me-2 text-danger"></i>Offboarding
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.avepoint.dashboard')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.avepoint.*') ? 'active' : '' }}"
                                   href="{{ route('admin.avepoint.dashboard') }}">
                                    <i class="bi bi-cloud-arrow-down-fill me-2 text-info"></i>AvePoint Backups
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.form-previews.onboarding', 'admin.form-previews.offboarding')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-eye me-1"></i>Manager Form Previews</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.form-previews.onboarding') ? 'active' : '' }}"
                                   href="{{ route('admin.form-previews.onboarding') }}" target="_blank">
                                    <i class="bi bi-person-plus me-2"></i>Onboarding Form
                                    <i class="bi bi-box-arrow-up-right ms-1 small opacity-50"></i>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.form-previews.offboarding') ? 'active' : '' }}"
                                   href="{{ route('admin.form-previews.offboarding') }}" target="_blank">
                                    <i class="bi bi-person-dash me-2"></i>Offboarding Form
                                    <i class="bi bi-box-arrow-up-right ms-1 small opacity-50"></i>
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.workflow-templates.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.workflow-templates.index') ? 'active' : '' }}"
                                   href="{{ route('admin.workflow-templates.index') }}">
                                    <i class="bi bi-diagram-3 me-2"></i>Workflow Templates
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/workflow-templates/*/builder') ? 'active' : '' }}"
                                   href="{{ route('admin.workflow-templates.index') }}"
                                   title="Open visual builder from any template row">
                                    <i class="bi bi-node-plus me-2"></i>Visual Builder
                                </a>
                            </li>
                            @endcanroute
                            {{-- ── Forms (moved from the standalone Forms menu) ── --}}
                            @canroute('admin.forms.index', 'admin.forms.create')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-ui-checks-grid me-1"></i>Forms</h6></li>
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
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Identity dropdown ── --}}
                    @canroute('admin.identity.users', 'admin.identity.group-mappings.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/identity*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-people-fill me-1"></i>Identity
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.identity.users', 'admin.identity.licenses', 'admin.identity.groups')
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
                            @endcanroute
                            @canroute('admin.identity.group-mappings.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/identity/group-mappings*') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.group-mappings.index') }}">
                                    <i class="bi bi-diagram-3 me-2"></i>Group Auto-Assignments
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.identity.contact-sync', 'admin.identity.linked-accounts', 'admin.identity.hr-import', 'admin.identity.sync-logs')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.contact-sync') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.contact-sync') }}">
                                    <i class="bi bi-arrow-repeat me-2"></i>Contact Sync
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.linked-accounts') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.linked-accounts') }}">
                                    <i class="bi bi-link-45deg me-2"></i>Linked Accounts
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.hr-import*') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.hr-import') }}">
                                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Oracle HR Import
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.identity.sync-logs') ? 'active' : '' }}"
                                   href="{{ route('admin.identity.sync-logs') }}">
                                    <i class="bi bi-clock-history me-2"></i>Sync Logs
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- Documentation, Marketing, Teamtailor & Admin Tools folded into the Admin menu below --}}

                    {{-- Create Ticket / My Tickets are employee links — kept off the admin menu bar --}}

                    {{-- ── AI dropdown (every AI Assistant page in one place) ── --}}
                    @canroute('admin.ai-assistant.knowledge.index', 'admin.ai-assistant.knowledge-gaps.index', 'admin.recruitment-ai.index', 'admin.ai-assistant.conversations.index', 'admin.ai-assistant.access.index', 'admin.settings.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/ai-assistant*') || request()->is('admin/recruitment-ai*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-robot me-1"></i>AI
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.ai-assistant.knowledge.index', 'admin.ai-assistant.knowledge.websites.index', 'admin.ai-assistant.knowledge-stats')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.knowledge.*') && ! request()->routeIs('admin.ai-assistant.knowledge.websites.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.knowledge.index') }}">
                                    <i class="bi bi-book me-2"></i>Knowledge Articles
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.knowledge.websites.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.knowledge.websites.index') }}">
                                    <i class="bi bi-globe2 me-2"></i>Websites
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.knowledge-stats') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.knowledge-stats') }}">
                                    <i class="bi bi-bar-chart me-2"></i>Knowledge Statistics
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.ai-assistant.knowledge-gaps.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.knowledge-gaps.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.knowledge-gaps.index') }}">
                                    <i class="bi bi-question-circle me-2"></i>Knowledge Gaps
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.recruitment-ai.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.recruitment-ai.*') ? 'active' : '' }}"
                                   href="{{ route('admin.recruitment-ai.index') }}">
                                    <i class="bi bi-person-check me-2"></i>Recruitment AI
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.ai-assistant.conversations.index', 'admin.ai-assistant.usage')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.conversations.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.conversations.index') }}">
                                    <i class="bi bi-chat-dots me-2"></i>Conversations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.usage') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.usage') }}">
                                    <i class="bi bi-graph-up me-2"></i>Usage
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.ai-assistant.access.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.access.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.access.index') }}">
                                    <i class="bi bi-shield-check me-2"></i>AI Access
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.ai-assistant.instructions.edit', 'admin.settings.index')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.ai-assistant.instructions.edit')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ai-assistant.instructions.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ai-assistant.instructions.edit') }}">
                                    <i class="bi bi-card-text me-2"></i>Instructions
                                </a>
                            </li>
                            @endcanroute
                            {{-- The Azure OpenAI connection is a card on General Settings --}}
                            @canroute('admin.settings.index')
                            <li>
                                <a class="dropdown-item" href="{{ route('admin.settings.index') }}#ai-assistant">
                                    <i class="bi bi-sliders me-2"></i>Assistant Settings
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Exams dropdown (practice exams for the team) ── --}}
                    @canroute('admin.exams.index', 'admin.exams.results.index', 'admin.exams.manage.index')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/exams*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-mortarboard me-1"></i>Exams
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            @canroute('admin.exams.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.exams.index', 'admin.exams.show') ? 'active' : '' }}"
                                   href="{{ route('admin.exams.index') }}">
                                    <i class="bi bi-pencil-square me-2"></i>Take an exam
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.exams.results.index', 'admin.exams.manage.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.exams.results.*') ? 'active' : '' }}"
                                   href="{{ route('admin.exams.results.index') }}">
                                    <i class="bi bi-bar-chart-line me-2"></i>Team results
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.exams.manage.*') ? 'active' : '' }}"
                                   href="{{ route('admin.exams.manage.index') }}">
                                    <i class="bi bi-collection me-2"></i>Exams &amp; question banks
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

                    {{-- ── Admin dropdown (Settings + Documentation + Marketing + Recruiting + Tools) ── --}}
                    @canroute(
                        'admin.settings.index', 'admin.mail-senders.index', 'admin.email-templates.index', 'admin.business-apps.index',
                        'admin.ticket-stats.index', 'admin.tickets.index', 'admin.announcements.index', 'admin.portal-documents.index',
                        'admin.archive.index', 'admin.greeting-lines.index', 'admin.knowbe4.index', 'admin.settings.locations',
                        'admin.branches.index', 'admin.settings.departments', 'admin.settings.domains', 'admin.settings.asset-types',
                        'admin.settings.internet-access-levels.index', 'admin.settings.provisioning-licenses', 'admin.api-docs', 'admin.hr-api-keys.index',
                        'admin.access-gateway.index', 'admin.access-gateway.audit', 'admin.users.index', 'admin.roles.index',
                        'admin.permissions.index', 'admin.server-status', 'admin.notification-rules.index', 'admin.sync-status',
                        'admin.email-log.index', 'admin.mail-delivery.index', 'admin.license-monitors.index', 'admin.phone-logs.index',
                        'admin.activity-logs', 'admin.smtp-relay.index', 'admin.documentation.index', 'admin.candidates.index',
                        'admin.email-marketing.settings', 'portal.marketing.dashboard', 'admin.signatures.index', 'admin.admin-links.index'
                    )
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->is('admin/settings*','admin/users*','admin/permissions*','admin/phone-logs*','admin/activity-logs*','admin/branches*','admin/notifications*','admin/license-monitors*','admin/internet-access-levels*','admin/email-templates*','admin/documentation*','admin/email-marketing*','admin/admin-links*','admin/jobs*','admin/candidates*','admin/signatures*','admin/access-gateway*','admin/smtp-relay*','admin/tickets*','admin/announcements*','admin/greeting-lines*','admin/knowbe4*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i>Admin
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-mega shadow">
                            @canroute('admin.settings.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.index') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.index') }}">
                                    <i class="bi bi-sliders me-2"></i>General Settings
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.mail-senders.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.mail-senders.*') ? 'active' : '' }}"
                                   href="{{ route('admin.mail-senders.index') }}">
                                    <i class="bi bi-envelope-at me-2"></i>Sender Addresses
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.email-templates.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-templates.*') ? 'active' : '' }}"
                                   href="{{ route('admin.email-templates.index') }}">
                                    <i class="bi bi-envelope-paper me-2"></i>Email Templates
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.business-apps.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.business-apps.*') ? 'active' : '' }}"
                                   href="{{ route('admin.business-apps.index') }}">
                                    <i class="bi bi-app-indicator me-2"></i>Business App Accounts
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.ticket-stats.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.ticket-stats.*') ? 'active' : '' }}"
                                   href="{{ route('admin.ticket-stats.index') }}">
                                    <i class="bi bi-ticket-detailed me-2"></i>Ticket Portal Stats
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.tickets.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.tickets.index') || request()->routeIs('admin.tickets.show') ? 'active' : '' }}"
                                   href="{{ route('admin.tickets.index') }}">
                                    <i class="bi bi-clock-history me-2"></i>Ticket Submissions
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.announcements.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}"
                                   href="{{ route('admin.announcements.index') }}">
                                    <i class="bi bi-megaphone-fill me-2"></i>Announcements
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.portal-documents.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.portal-documents.*') ? 'active' : '' }}"
                                   href="{{ route('admin.portal-documents.index') }}">
                                    <i class="bi bi-folder2-open me-2"></i>Employee Documents
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.archive.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.archive.*') ? 'active' : '' }}"
                                   href="{{ route('admin.archive.index') }}">
                                    <i class="bi bi-archive me-2"></i>Document Archive
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.greeting-lines.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.greeting-lines.*') ? 'active' : '' }}"
                                   href="{{ route('admin.greeting-lines.index') }}">
                                    <i class="bi bi-chat-heart-fill me-2"></i>Greeting Lines
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.knowbe4.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.knowbe4.*') ? 'active' : '' }}"
                                   href="{{ route('admin.knowbe4.index') }}">
                                    <i class="bi bi-shield-check me-2"></i>Security Awareness
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.locations', 'admin.branches.index', 'admin.settings.departments', 'admin.settings.domains', 'admin.settings.asset-types', 'admin.settings.internet-access-levels.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-building me-1"></i>Organisation</h6></li>
                            @endcanroute
                            @canroute('admin.settings.locations')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.locations') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.locations') }}">
                                    <i class="bi bi-geo-alt-fill me-2"></i>Locations
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.branches.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/branches*') ? 'active' : '' }}"
                                   href="{{ route('admin.branches.index') }}">
                                    <i class="bi bi-building me-2"></i>Branches
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.departments')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.departments') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.departments') }}">
                                    <i class="bi bi-grid-1x2-fill me-2"></i>Departments
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.domains')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.domains') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.domains') }}">
                                    <i class="bi bi-globe me-2"></i>Allowed Domains
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.asset-types')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.asset-types') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.asset-types') }}">
                                    <i class="bi bi-tags-fill me-2"></i>Asset Types & Codes
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.internet-access-levels.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.internet-access-levels.*') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.internet-access-levels.index') }}">
                                    <i class="bi bi-wifi me-2"></i>Internet Access Levels
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.settings.provisioning-licenses', 'admin.api-docs', 'admin.hr-api-keys.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-cloud-check me-1"></i>Provisioning</h6></li>
                            @endcanroute
                            @canroute('admin.settings.provisioning-licenses')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.settings.provisioning-licenses') ? 'active' : '' }}"
                                   href="{{ route('admin.settings.provisioning-licenses') }}">
                                    <i class="bi bi-patch-check-fill me-2"></i>Provisioning Licenses
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.api-docs')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/api-docs*') ? 'active' : '' }}"
                                   href="{{ route('admin.api-docs') }}">
                                    <i class="bi bi-code-slash me-2"></i>HR API Docs & Keys
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.hr-api-keys.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.hr-api-keys.*') ? 'active' : '' }}"
                                   href="{{ route('admin.hr-api-keys.index') }}">
                                    <i class="bi bi-key me-2"></i>HR API Keys
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.access-gateway.index', 'admin.access-gateway.audit', 'admin.users.index', 'admin.roles.index', 'admin.permissions.index')
                            <li><hr class="dropdown-divider"></li>
                            @endcanroute
                            @canroute('admin.access-gateway.index', 'admin.access-gateway.audit')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/access-gateway*') ? 'active' : '' }}"
                                   href="{{ route(auth()->user()->can('manage-agw-allowlist') ? 'admin.access-gateway.index' : 'admin.access-gateway.audit') }}">
                                    <i class="bi bi-shield-lock me-2"></i>Access Gateway
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.users.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/users*') ? 'active' : '' }}"
                                   href="{{ route('admin.users.index') }}">
                                    <i class="bi bi-person-badge-fill me-2"></i>Users
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.roles.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/roles*') ? 'active' : '' }}"
                                   href="{{ route('admin.roles.index') }}">
                                    <i class="bi bi-people-fill me-2"></i>Roles
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.permissions.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/permissions*') ? 'active' : '' }}"
                                   href="{{ route('admin.permissions.index') }}">
                                    <i class="bi bi-shield-lock-fill me-2"></i>Permissions
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.server-status', 'admin.notification-rules.index', 'admin.sync-status', 'admin.email-log.index', 'admin.mail-delivery.index', 'admin.license-monitors.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-layers me-1"></i>Platform</h6></li>
                            @canroute('admin.server-status')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.server-status') ? 'active' : '' }}"
                                   href="{{ route('admin.server-status') }}">
                                    <i class="bi bi-hdd-rack-fill me-2"></i>Server Status
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.notification-rules.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.notification-rules.index') ? 'active' : '' }}"
                                   href="{{ route('admin.notification-rules.index') }}">
                                    <i class="bi bi-funnel-fill me-2"></i>Notification Rules
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.sync-status')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.sync-status') ? 'active' : '' }}"
                                   href="{{ route('admin.sync-status') }}">
                                    <i class="bi bi-arrow-repeat me-2"></i>Sync Status
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.email-log.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-log.index') ? 'active' : '' }}"
                                   href="{{ route('admin.email-log.index') }}">
                                    <i class="bi bi-envelope-check me-2"></i>Email Log
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.mail-delivery.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.mail-delivery.*') ? 'active' : '' }}"
                                   href="{{ route('admin.mail-delivery.index') }}">
                                    <i class="bi bi-send-check me-2 text-primary"></i>Mail Delivery (SES)
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.license-monitors.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.license-monitors.index') ? 'active' : '' }}"
                                   href="{{ route('admin.license-monitors.index') }}">
                                    <i class="bi bi-clipboard2-pulse me-2"></i>License Monitors
                                </a>
                            </li>
                            @endcanroute
                            @endcanroute
                            @canroute('admin.phone-logs.index', 'admin.activity-logs', 'admin.smtp-relay.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-journal-text me-1"></i>Logs</h6></li>
                            @canroute('admin.phone-logs.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/phone-logs*') ? 'active' : '' }}"
                                   href="{{ route('admin.phone-logs.index') }}">
                                    <i class="bi bi-telephone-inbound-fill me-2"></i>Phone Logs
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.activity-logs', 'admin.access-stats.index')
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/activity-logs*') ? 'active' : '' }}"
                                   href="{{ route('admin.activity-logs') }}">
                                    <i class="bi bi-shield-check me-2"></i>Audit Log
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.access-stats.*') ? 'active' : '' }}"
                                   href="{{ route('admin.access-stats.index') }}">
                                    <i class="bi bi-people me-2"></i>Access Analytics
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.smtp-relay.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.smtp-relay.*') ? 'active' : '' }}"
                                   href="{{ route('admin.smtp-relay.index') }}">
                                    <i class="bi bi-envelope-paper me-2"></i>SMTP Relay Log
                                </a>
                            </li>
                            @endcanroute
                            @endcanroute
                            {{-- ── Documentation (folded into Admin) ── --}}
                            @canroute('admin.documentation.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/documentation*') ? 'active' : '' }}"
                                   href="{{ route('admin.documentation.index') }}">
                                    <i class="bi bi-book-fill me-2"></i>Documentation
                                </a>
                            </li>
                            @endcanroute
                            {{-- ── Recruitment / Teamtailor (folded into Admin) ── --}}
                            @canroute('admin.jobs.index', 'admin.candidates.index')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-people-fill me-1"></i>Recruitment</h6></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/jobs*') ? 'active' : '' }}"
                                   href="{{ route('admin.jobs.index') }}">
                                    <i class="bi bi-briefcase me-2"></i>Jobs
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/candidates*') ? 'active' : '' }}"
                                   href="{{ route('admin.candidates.index') }}">
                                    <i class="bi bi-person-rolodex me-2"></i>Candidates
                                </a>
                            </li>
                            @endcanroute
                            {{-- ── Email Marketing (folded into Admin) ── --}}
                            @canroute('admin.email-marketing.settings', 'admin.email-marketing.suppressions', 'admin.email-marketing.quota', 'admin.email-marketing.senders.index', 'portal.marketing.dashboard')
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-secondary"><i class="bi bi-envelope-paper me-1"></i>Email Marketing</h6></li>
                            @canroute('admin.email-marketing.settings')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-marketing.settings') ? 'active' : '' }}"
                                   href="{{ route('admin.email-marketing.settings') }}">
                                    <i class="bi bi-gear me-2"></i>SES Settings
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.email-marketing.suppressions', 'admin.email-marketing.quota')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-marketing.suppressions') ? 'active' : '' }}"
                                   href="{{ route('admin.email-marketing.suppressions') }}">
                                    <i class="bi bi-shield-x me-2"></i>Suppression List
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-marketing.quota') ? 'active' : '' }}"
                                   href="{{ route('admin.email-marketing.quota') }}">
                                    <i class="bi bi-speedometer2 me-2"></i>Quota &amp; Status
                                </a>
                            </li>
                            @endcanroute
                            @canroute('admin.email-marketing.senders.index')
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.email-marketing.senders.*') ? 'active' : '' }}"
                                   href="{{ route('admin.email-marketing.senders.index') }}">
                                    <i class="bi bi-person-badge me-2"></i>Sender Allowlist
                                </a>
                            </li>
                            @endcanroute
                            @canroute('portal.marketing.dashboard')
                            <li>
                                <a class="dropdown-item" href="{{ route('portal.marketing.dashboard') }}" target="_blank">
                                    <i class="bi bi-grid me-2 text-info"></i>Marketing Portal
                                    <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:.65rem"></i>
                                </a>
                            </li>
                            @endcanroute
                            @endcanroute
                            {{-- ── Email Signatures ── --}}
                            @canroute('admin.signatures.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/signatures*') ? 'active' : '' }}"
                                   href="{{ route('admin.signatures.index') }}">
                                    <i class="bi bi-envelope-paper-fill me-2"></i>Email Signatures
                                </a>
                            </li>
                            @endcanroute
                            {{-- ── Admin Tools (folded into Admin) ── --}}
                            @canroute('admin.admin-links.index')
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->is('admin/admin-links*') ? 'active' : '' }}"
                                   href="{{ route('admin.admin-links.index') }}">
                                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Admin Tools
                                </a>
                            </li>
                            @endcanroute
                        </ul>
                    </li>
                    @endcanroute

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
        {{-- `status` as well as `success`: it is Laravel's own convention and
             what several controllers already return, so a page that flashed it
             here showed nothing at all. --}}
        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

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
