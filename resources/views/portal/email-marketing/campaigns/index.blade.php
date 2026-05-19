@extends('layouts.portal')

@section('title', 'Campaigns')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <a href="{{ route('portal.marketing.campaigns.index', ['domain' => $domain ?? null]) }}"
               class="btn btn-sm {{ ($showArchived ?? false) ? 'btn-outline-secondary' : 'btn-secondary' }}">Active</a>
            <a href="{{ route('portal.marketing.campaigns.index', ['archived' => 1, 'domain' => $domain ?? null]) }}"
               class="btn btn-sm {{ ($showArchived ?? false) ? 'btn-secondary' : 'btn-outline-secondary' }}">
                <i class="bi bi-archive me-1"></i>Archived
            </a>

            <form method="GET" class="d-flex gap-1 ms-2">
                @if ($showArchived ?? false)<input type="hidden" name="archived" value="1">@endif
                <select name="domain" class="form-select form-select-sm" style="max-width: 220px"
                        onchange="this.form.submit()">
                    <option value="">All domains</option>
                    @foreach (($domains ?? []) as $d)
                        <option value="{{ $d }}" @selected(($domain ?? '') === $d)>@&#64;{{ $d }}</option>
                    @endforeach
                </select>
                @if (! empty($domain ?? ''))
                    <a href="{{ route('portal.marketing.campaigns.index', $showArchived ? ['archived' => 1] : []) }}"
                       class="btn btn-sm btn-link" title="Clear domain filter">
                        <i class="bi bi-x-circle"></i>
                    </a>
                @endif
            </form>
        </div>
        <a href="{{ route('portal.marketing.campaigns.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New campaign
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Subject</th><th>From</th><th>Domain</th><th>List / Segment</th><th>Status</th><th>Schedule</th><th>Sent</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($campaigns as $c)
                    @php
                        $fromDomain = $c->from_email ? \Illuminate\Support\Str::after($c->from_email, '@') : null;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('portal.marketing.campaigns.show', $c) }}"><strong>{{ $c->name }}</strong></a>
                            @if ($c->archived_at)
                                <span class="badge bg-light text-muted ms-1"><i class="bi bi-archive"></i> archived</span>
                            @endif
                        </td>
                        <td><small>{{ $c->subject }}</small></td>
                        <td>
                            <small>
                                <strong>{{ $c->from_name ?: '—' }}</strong>
                                @if ($c->from_email)
                                    <br><span class="text-muted">&lt;{{ $c->from_email }}&gt;</span>
                                @endif
                                @if ($c->reply_to)
                                    <br><span class="text-muted" title="Reply-to"><i class="bi bi-reply"></i> {{ $c->reply_to }}</span>
                                @endif
                            </small>
                        </td>
                        <td>
                            @if ($fromDomain)
                                <span class="badge bg-light text-dark border"><i class="bi bi-globe me-1"></i>{{ $fromDomain }}</span>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td>{{ $c->list?->name ?: ($c->segment_id ? 'Segment #' . $c->segment_id : '—') }}</td>
                        <td>
                            <span class="badge bg-{{ match($c->status) {
                                'sent' => 'success',
                                'sending' => 'primary',
                                'scheduled' => 'warning',
                                'paused' => 'secondary',
                                'failed' => 'danger',
                                default => 'light text-dark',
                            } }} text-capitalize">{{ $c->status }}</span>
                        </td>
                        <td><small>{{ $c->scheduled_at?->format('Y-m-d H:i') ?: '—' }}</small></td>
                        <td><small>{{ $c->total_sent }} / {{ $c->total_recipients }}</small></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('portal.marketing.campaigns.duplicate', $c) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" title="Duplicate"><i class="bi bi-files"></i></button>
                            </form>
                            <form method="POST" action="{{ route('portal.marketing.campaigns.archive', $c) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-warning"
                                        title="{{ $c->archived_at ? 'Restore' : 'Archive' }}">
                                    <i class="bi bi-{{ $c->archived_at ? 'arrow-counterclockwise' : 'archive' }}"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No campaigns to show.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $campaigns->links() }}</div>
    </div>
</div>
@endsection
