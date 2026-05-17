@php
    $route = request()->route()->getName();
    $isOn = fn ($prefix) => str_starts_with((string) $route, $prefix);
@endphp
<ul class="nav nav-pills mb-4 small">
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.dashboard') ? 'active' : '' }}"
           href="{{ route('portal.marketing.dashboard') }}">
            <i class="bi bi-grid me-1"></i>Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.lists') ? 'active' : '' }}"
           href="{{ route('portal.marketing.lists.index') }}">
            <i class="bi bi-list-ul me-1"></i>Lists
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.subscribers') ? 'active' : '' }}"
           href="{{ route('portal.marketing.subscribers.index') }}">
            <i class="bi bi-people me-1"></i>Subscribers
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.tags') ? 'active' : '' }}"
           href="{{ route('portal.marketing.tags.index') }}">
            <i class="bi bi-tags me-1"></i>Tags
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.segments') ? 'active' : '' }}"
           href="{{ route('portal.marketing.segments.index') }}">
            <i class="bi bi-funnel me-1"></i>Segments
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.templates') ? 'active' : '' }}"
           href="{{ route('portal.marketing.templates.index') }}">
            <i class="bi bi-file-earmark-text me-1"></i>Templates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isOn('portal.marketing.campaigns') ? 'active' : '' }}"
           href="{{ route('portal.marketing.campaigns.index') }}">
            <i class="bi bi-megaphone me-1"></i>Campaigns
        </a>
    </li>
</ul>
