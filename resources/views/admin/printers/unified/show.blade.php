@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-printer-fill me-2 text-primary"></i>{{ $printer->printer_name }}</h4>
            <small class="text-muted">{{ $printer->branch?->name ?? '—' }} · {{ $printer->ip_address ?? '—' }} · {{ $printer->manufacturer }} {{ $printer->model }}</small>
        </div>
        <div>
            <a href="{{ route('admin.printers.unified.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a href="{{ route('admin.printers.show', $printer) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-info-circle me-1"></i>Full record</a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="unifiedPrinterTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-asset" type="button">Asset</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-snmp" type="button">SNMP</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cups" type="button">CUPS</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-alerts" type="button">Alerts <span class="badge bg-danger ms-1">{{ $alerts->where('status', 'open')->count() }}</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-usage" type="button">Usage</button></li>
    </ul>

    <div class="tab-content">
        {{-- Asset --}}
        <div class="tab-pane fade show active" id="tab-asset">
            @if ($printer->device)
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Asset Code</dt>
                            <dd class="col-sm-9"><code>{{ $printer->device->asset_code ?? '—' }}</code></dd>

                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">{{ $printer->device->status ?? '—' }}</dd>

                            <dt class="col-sm-3">Serial / MAC</dt>
                            <dd class="col-sm-9">{{ $printer->serial_number ?? '—' }} · {{ $printer->mac_address ?? '—' }}</dd>

                            <dt class="col-sm-3">Branch</dt>
                            <dd class="col-sm-9">{{ $printer->branch?->name ?? '—' }}</dd>

                            <dt class="col-sm-3">Location</dt>
                            <dd class="col-sm-9">{{ $printer->locationLabel() }}</dd>

                            @if ($printer->device->purchase_cost ?? null)
                                <dt class="col-sm-3">Purchase Cost</dt>
                                <dd class="col-sm-9">{{ $printer->device->purchase_cost }} {{ $printer->device->currency ?? '' }}</dd>
                            @endif

                            @if ($printer->device->warranty_expiry ?? null)
                                <dt class="col-sm-3">Warranty Expires</dt>
                                <dd class="col-sm-9">{{ \Carbon\Carbon::parse($printer->device->warranty_expiry)->format('Y-m-d') }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            @else
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>This printer has no linked ITAM device record (Unmanaged).</div>
            @endif
        </div>

        {{-- SNMP --}}
        <div class="tab-pane fade" id="tab-snmp">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    @if ($printer->snmp_enabled)
                        <div class="row g-3">
                            <div class="col-md-4">
                                <small class="text-muted">SNMP Last Polled</small>
                                <div class="fw-semibold">{{ $printer->snmp_last_polled_at?->diffForHumans() ?? 'never' }}</div>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted">Status</small>
                                <div><span class="badge {{ $printer->statusBadgeClass() }}">{{ $printer->statusLabel() }}</span></div>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted">Error State</small>
                                <div><span class="badge {{ $printer->errorBadgeClass() }}">{{ $printer->errorLabel() }}</span></div>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-uppercase small fw-bold text-muted">Toner Levels</h6>
                        <div class="row g-2">
                            @foreach ($printer->tonerLevels() as $name => $level)
                                <div class="col-md-3">
                                    <div class="d-flex justify-content-between"><span>{{ $name }}</span><strong>{{ $level !== null ? $level.'%' : '—' }}</strong></div>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar {{ \App\Models\Printer::tonerBarClass($level) }}" style="width: {{ $level ?? 0 }}%; background-color: {{ \App\Models\Printer::tonerColor($name) }};"></div>
                                    </div>
                                </div>
                            @endforeach
                            @if ($printer->wasteFillPercent() !== null)
                                <div class="col-md-3">
                                    <div class="d-flex justify-content-between"><span>Waste</span><strong>{{ $printer->wasteFillPercent() }}% full</strong></div>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar {{ $printer->wasteFillPercent() >= 95 ? 'bg-danger' : ($printer->wasteFillPercent() >= 80 ? 'bg-warning' : 'bg-secondary') }}" style="width: {{ $printer->wasteFillPercent() }}%;"></div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if ($printer->paper_trays)
                            <hr>
                            <h6 class="text-uppercase small fw-bold text-muted">Paper Trays</h6>
                            <ul class="list-unstyled small mb-0">
                                @foreach ((array) $printer->paper_trays as $tray)
                                    <li>{{ $tray['name'] ?? 'Tray' }}: {{ $tray['current'] ?? 0 }} / {{ $tray['max'] ?? 0 }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <div class="alert alert-info mb-0">SNMP monitoring is not enabled for this printer.</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- CUPS --}}
        <div class="tab-pane fade" id="tab-cups">
            @if ($printer->cupsPrinter)
                @php $cups = $printer->cupsPrinter; @endphp
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <dl class="row mb-3">
                            <dt class="col-sm-3">Queue</dt>
                            <dd class="col-sm-9"><code>{{ $cups->queue_name }}</code></dd>
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9"><span class="badge {{ $cups->statusBadgeClass() }}">{{ $cups->status }}</span></dd>
                            <dt class="col-sm-3">Driver</dt>
                            <dd class="col-sm-9">{{ $cups->driver }}</dd>
                            <dt class="col-sm-3">URI</dt>
                            <dd class="col-sm-9"><code class="small">{{ $cups->getCupsUri() }}</code></dd>
                            <dt class="col-sm-3">Last Checked</dt>
                            <dd class="col-sm-9">{{ $cups->last_checked_at?->diffForHumans() ?? '—' }}</dd>
                        </dl>

                        <h6 class="text-uppercase small fw-bold text-muted mt-3">Recent Print Jobs</h6>
                        @if ($cups->printJobs->isNotEmpty())
                            <table class="table table-sm small mb-0">
                                <thead><tr><th>Job</th><th>Title</th><th>Pages</th><th>Status</th></tr></thead>
                                <tbody>
                                    @foreach ($cups->printJobs as $j)
                                        <tr><td>{{ $j->cups_job_id }}</td><td>{{ $j->title }}</td><td>{{ $j->pages }}</td><td>{{ $j->status }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted small mb-0">No recorded jobs.</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="alert alert-warning">No CUPS queue is linked to this printer. Run <code>php artisan printers:link-cups</code> to auto-link by IP.</div>
            @endif
        </div>

        {{-- Alerts --}}
        <div class="tab-pane fade" id="tab-alerts">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead class="table-light"><tr><th>Severity</th><th>Title</th><th>Status</th><th>First Seen</th><th>Last Seen</th><th>Email</th></tr></thead>
                        <tbody>
                        @forelse ($alerts as $a)
                            <tr>
                                <td><span class="badge {{ $a->severityBadgeClass() }}">{{ $a->severity }}</span></td>
                                <td>{{ $a->title }}</td>
                                <td><span class="badge {{ $a->statusBadgeClass() }}">{{ $a->status }}</span></td>
                                <td>{{ optional($a->first_seen)->format('Y-m-d H:i') }}</td>
                                <td>{{ optional($a->last_seen)->format('Y-m-d H:i') }}</td>
                                <td>
                                    @if ($a->email_sent_at)
                                        <span class="text-success" title="{{ $a->email_sent_at->format('Y-m-d H:i') }}"><i class="bi bi-envelope-check"></i></span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No alerts on record.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Usage --}}
        <div class="tab-pane fade" id="tab-usage">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase small fw-bold text-muted">Counter Snapshots — last 90 days</h6>
                    @if ($snapshots->isNotEmpty())
                        @php
                            $first = $snapshots->first();
                            $last  = $snapshots->last();
                            $diff  = ($last->page_total ?? 0) - ($first->page_total ?? 0);
                            $diff  = $diff > 0 ? $diff : 0;
                        @endphp
                        <p class="mb-3">From {{ $first->snapshot_date->format('Y-m-d') }} ({{ number_format($first->page_total ?? 0) }}) to {{ $last->snapshot_date->format('Y-m-d') }} ({{ number_format($last->page_total ?? 0) }}) — <strong>{{ number_format($diff) }} pages</strong>.</p>
                        <table class="table table-sm small mb-0">
                            <thead><tr><th>Date</th><th>Total</th><th>Color</th><th>Mono</th><th>Print</th><th>Copy</th><th>Scan</th><th>Fax</th></tr></thead>
                            <tbody>
                                @foreach ($snapshots->reverse()->take(30) as $s)
                                    <tr>
                                        <td>{{ $s->snapshot_date->format('Y-m-d') }}</td>
                                        <td>{{ number_format($s->page_total ?? 0) }}</td>
                                        <td>{{ number_format($s->page_color ?? 0) }}</td>
                                        <td>{{ number_format($s->page_mono ?? 0) }}</td>
                                        <td>{{ number_format($s->page_print ?? 0) }}</td>
                                        <td>{{ number_format($s->page_copy ?? 0) }}</td>
                                        <td>{{ number_format($s->page_scan ?? 0) }}</td>
                                        <td>{{ number_format($s->page_fax ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted mb-0">No counter snapshots yet — they're written daily at 23:55.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
