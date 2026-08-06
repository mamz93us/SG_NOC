@extends('layouts.admin')

@section('title', 'Email Templates')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-envelope-paper me-2"></i>Email Templates</h4>
            <p class="text-muted mb-0 small">
                Every designed email SG NOC sends. Edit the wording or the layout; leave one alone and it
                keeps sending its original design.
            </p>
        </div>
        <a href="{{ route('admin.mail-senders.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-envelope-at me-1"></i>Sender Addresses
        </a>
    </div>

    @if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    @foreach($grouped as $group => $templates)
    <div class="card mb-3">
        <div class="card-header fw-semibold">{{ $group }}</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:32%">Email</th>
                        <th>What it is</th>
                        <th style="width:15%">Sends as</th>
                        <th style="width:12%">Status</th>
                        <th style="width:1%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $key => $meta)
                    @php $ov = $overrides->get($key); @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.email-templates.edit', $key) }}" class="fw-semibold text-decoration-none">
                                {{ $meta['label'] }}
                            </a>
                            <div class="text-muted font-monospace" style="font-size:11px">{{ $key }}</div>
                        </td>
                        <td class="small text-muted">{{ $meta['description'] }}</td>
                        <td class="small">{{ $senders[$meta['service']]['label'] ?? $meta['service'] }}</td>
                        <td>
                            @if(! $ov)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Original</span>
                            @elseif(! $ov->is_active)
                                <span class="badge bg-warning-subtle text-warning-emphasis">Edited · paused</span>
                            @else
                                <span class="badge bg-primary-subtle text-primary-emphasis">
                                    Customised{{ $ov->hasSubject() && $ov->hasBody() ? '' : ($ov->hasSubject() ? ' · subject' : ' · body') }}
                                </span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.email-templates.edit', $key) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    <div class="alert alert-light border small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Two kinds of mail are not listed here because they have no fixed design:
        <strong>workflow action emails</strong>, whose subject and body are written per action in the workflow
        builder, and <strong>monitoring alert emails</strong>, which are generated from the alert rule itself.
        The AirPrint setup email is also excluded — it embeds a generated QR image that cannot survive a
        re-layout.
    </div>
</div>
@endsection
