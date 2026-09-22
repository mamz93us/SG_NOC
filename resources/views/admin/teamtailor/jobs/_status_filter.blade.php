{{--
    Status pills over a list of Teamtailor jobs. $route is the page's route
    name, $status the status chosen; $counts (status => jobs) is optional.
--}}
@php
    $hints = [
        'all' => 'Every job, open and closed',
        'published' => 'On the career site and open for applications',
        'unlisted' => 'Closed: off the career site and no public applications, though the job link still works',
        'archived' => 'Closed for applications and moved to the archive',
        'draft' => 'Saved but never published',
        'scheduled' => 'Published on its start date',
    ];
@endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <ul class="nav nav-pills small">
        @foreach (\App\Services\Teamtailor\TeamtailorApiService::JOB_STATUSES as $key)
            <li class="nav-item">
                <a class="nav-link py-1 {{ $status === $key ? 'active' : '' }}"
                   href="{{ route($route, $key === 'all' ? [] : ['status' => $key]) }}"
                   title="{{ $hints[$key] ?? '' }}">
                    {{ ucfirst($key) }}
                    @isset($counts)
                        <span class="badge {{ $status === $key ? 'bg-light text-dark' : 'bg-secondary' }} ms-1">{{ $counts[$key] ?? 0 }}</span>
                    @endisset
                </a>
            </li>
        @endforeach
    </ul>
    <small class="text-muted">Closed jobs are <strong>Unlisted</strong> (off the career site) or <strong>Archived</strong>.</small>
</div>
