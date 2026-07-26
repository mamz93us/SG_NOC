@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Transport Rule Preview</h1>
        <small class="text-muted">What the server-side rules stamp on New Outlook / OWA / mobile — sample data shown in place of the live Azure fields</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Templates
        </a>
    </div>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    Values like <code>Ahmed Al-Rashidi</code>, the phone and the extension are <strong>samples</strong> — at send time Exchange fills each from the sender's own Azure profile
    (<code>%%DisplayName%%</code>, <code>%%FaxNumber%%</code>, …). The <strong>size</strong> shown is the real HTML deployed to the rule; anything over
    <strong>{{ number_format($limit) }}</strong> chars is rejected by Exchange (usually a base64 logo or Word-pasted cruft).
</div>

<div class="row g-4">
    @foreach($rules as $r)
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2 py-3">
                <span class="fw-semibold">{{ $r['label'] }}</span>
                <span class="badge bg-light text-dark border font-monospace">{{ $r['domain'] }}</span>
                @if($r['gender'])
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ ucfirst($r['gender']) }}</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border">All</span>
                @endif

                <span class="ms-auto d-flex align-items-center gap-2">
                    @if(!$r['exists'])
                        <span class="badge bg-danger-subtle text-danger-emphasis border">No template</span>
                    @else
                        <span class="text-muted small">{{ $r['template'] }}</span>
                        @if($r['oversized'])
                            <span class="badge bg-danger-subtle text-danger-emphasis border" title="Exchange will reject this">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ number_format($r['length']) }} chars — too big
                            </span>
                        @else
                            <span class="badge bg-success-subtle text-success-emphasis border">{{ number_format($r['length']) }} chars — OK</span>
                        @endif
                    @endif
                </span>
            </div>
            <div class="card-body">
                @if(!$r['exists'])
                    <div class="text-muted py-4 text-center">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        No active template for <code>{{ $r['domain'] }}</code>@if($r['gender']) ({{ $r['gender'] }})@endif —
                        create one in <a href="{{ route('admin.signatures.create') }}">New Template</a>.
                    </div>
                @else
                    <div class="border rounded p-3 bg-white" style="overflow-x:auto;">
                        {!! $r['preview'] !!}
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

@endsection
