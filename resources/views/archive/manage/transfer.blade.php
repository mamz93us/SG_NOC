@extends('layouts.archive')

@section('title', 'Transfer to Azure')

@php
    // Sizes are shown in whichever unit keeps them readable; a transfer measured
    // in "380,000 MB" tells nobody anything.
    $human = function (?int $bytes): string {
        if (! $bytes) { return '—'; }
        return $bytes >= 1073741824
            ? number_format($bytes / 1073741824, 1).' GB'
            : number_format($bytes / 1048576, 0).' MB';
    };
    $duration = function (?int $seconds): string {
        if (! $seconds) { return '—'; }
        if ($seconds < 3600) { return ceil($seconds / 60).' min'; }
        if ($seconds < 86400) { return round($seconds / 3600, 1).' hours'; }
        return round($seconds / 86400, 1).' days';
    };
@endphp

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('archive.manage.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Manage
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">Transfer to Azure</span>
    </div>

    @if (! $source)
        <div class="arc-card arc-empty">Set the ArcMate connection up first.</div>
    @else

    {{-- ── Where it stands ─────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">In Azure</div>
                <div class="h4 mb-0">{{ number_format($totals['done']) }}</div>
                <div class="arc-muted small">files · {{ $human($totals['bytes_done']) }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Still on ArcMate</div>
                <div class="h4 mb-0">{{ number_format($totals['pending']) }}</div>
                <div class="arc-muted small">
                    files
                    @if ($estimatedRemaining)
                        · about {{ $human($estimatedRemaining) }}
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Speed</div>
                <div class="h4 mb-0">{{ $bytesPerSecond ? number_format($bytesPerSecond / 1048576, 1).' MB/s' : '—' }}</div>
                <div class="arc-muted small">measured, recent runs</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Time left</div>
                <div class="h4 mb-0">{{ $duration($estimatedSeconds) }}</div>
                {{-- Said plainly: ArcMate stored 0 for every file size, so what is
                     left can only be estimated from what has already moved. --}}
                <div class="arc-muted small">estimate — ArcMate recorded no file sizes</div>
            </div>
        </div>
    </div>

    @if ($totals['total'] > 0)
        <div class="arc-card p-3 mb-3">
            @php($overall = (int) round($totals['done'] / max(1, $totals['total']) * 100))
            <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold">{{ $overall }}% of files transferred</span>
                <span class="arc-muted">
                    {{ $allowedNow ? 'Running now' : 'Waiting for the transfer window' }}
                </span>
            </div>
            <div class="progress" style="height:10px">
                <div class="progress-bar" style="width: {{ $overall }}%; background: var(--green)"></div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- ── Settings ────────────────────────────────────────── --}}
        <div class="col-lg-4">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">When it runs</h2>

                <form method="POST" action="{{ route('archive.manage.transfer.settings') }}">
                    @csrf
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="transfer_enabled" value="1"
                               id="tEnabled" @checked($source->transfer_enabled)>
                        <label class="form-check-label small" for="tEnabled">Transfer switched on</label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="transfer_anytime" value="1"
                               id="tAnytime" @checked($source->transfer_anytime)>
                        <label class="form-check-label small" for="tAnytime">
                            Allow during working hours
                            <span class="arc-muted d-block">
                                The share belongs to the server people still scan into all day.
                            </span>
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="transfer_weekend_all_day" value="1"
                               id="tWeekend" @checked($source->transfer_weekend_all_day)>
                        <label class="form-check-label small" for="tWeekend">All day Friday and Saturday</label>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">From</label>
                            <input type="time" class="form-control form-control-sm" name="transfer_window_start"
                                   value="{{ $source->transfer_window_start ?: '19:00' }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">Until</label>
                            <input type="time" class="form-control form-control-sm" name="transfer_window_end"
                                   value="{{ $source->transfer_window_end ?: '07:00' }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">Speed cap (MB/s, blank for none)</label>
                            <input type="number" min="0" class="form-control form-control-sm" name="transfer_speed_mbps"
                                   value="{{ $source->transfer_speed_mbps }}">
                        </div>

                        {{-- Which documents to move, by capture date. 372 GB in one
                             decision is a lot; this makes it several. Either end may be
                             left blank. --}}
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">Scanned from</label>
                            <input type="date" class="form-control form-control-sm" name="transfer_from"
                                   value="{{ $source->transfer_from }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">Scanned until</label>
                            <input type="date" class="form-control form-control-sm" name="transfer_to"
                                   value="{{ $source->transfer_to }}">
                        </div>
                        <div class="col-12">
                            <div class="form-text small">
                                @if ($pendingInRange !== null)
                                    <strong>{{ number_format($pendingInRange) }}</strong> file(s) waiting inside that range.
                                    Anything outside it is left alone until you widen it.
                                @else
                                    Leave both blank to move everything, newest first.
                                @endif
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-brand btn-sm w-100 mt-3">Save</button>
                </form>
            </div>

            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Checks</h2>

                <form method="POST" action="{{ route('archive.manage.transfer.verify') }}" class="mb-2">
                    @csrf
                    <input type="hidden" name="count" value="20">
                    <button class="btn btn-outline-secondary btn-sm w-100">Verify 20 transferred files</button>
                </form>

                @if ($lastVerify)
                    @php($result = $lastVerify->result ?? [])
                    <p class="small arc-muted mb-0">
                        Last check {{ $lastVerify->finished_at?->diffForHumans() }}:
                        {{ $result['ok'] ?? 0 }} of {{ $result['checked'] ?? 0 }} matched.
                        @if (! empty($result['mismatched']))
                            <span class="text-danger d-block">
                                {{ count($result['mismatched']) }} did not — see the log.
                            </span>
                        @endif
                    </p>
                @else
                    <p class="small arc-muted mb-0">
                        Nothing checked yet. Every file is verified as it is copied; this re-reads
                        a sample afterwards, which is what has to be green before ArcMate is switched off.
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Per archive ─────────────────────────────────────── --}}
        <div class="col-lg-8">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">By archive</h2>

                @if ($archives->isEmpty())
                    <p class="arc-muted small mb-0">No archives set up yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead>
                                <tr><th>Archive</th><th style="width:30%">Progress</th><th>Files</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($archives as $row)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $row->archive->displayName() }}</div>
                                            @if ($row->archive->transfer_paused)
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis">paused</span>
                                            @elseif (! $row->archive->readable)
                                                <span class="badge bg-warning-subtle text-warning-emphasis">encrypted — skipped</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="progress" style="height:8px">
                                                <div class="progress-bar" style="width: {{ $row->percent }}%; background: var(--green)"></div>
                                            </div>
                                            <span class="arc-muted small">{{ $row->percent }}% · {{ $human($row->bytes_done) }}</span>
                                        </td>
                                        <td class="arc-muted small">
                                            {{ number_format($row->done) }} / {{ number_format($row->total) }}
                                        </td>
                                        <td class="text-end">
                                            @if ($row->archive->readable)
                                                <form method="POST"
                                                      action="{{ route('archive.manage.transfer.archive', $row->archive) }}"
                                                      class="d-inline m-0">
                                                    @csrf
                                                    <input type="hidden" name="action"
                                                           value="{{ $row->archive->transfer_paused ? 'resume' : 'pause' }}">
                                                    <button class="btn btn-sm btn-outline-secondary">
                                                        {{ $row->archive->transfer_paused ? 'Resume' : 'Pause' }}
                                                    </button>
                                                </form>
                                                @if ($row->pending > 0)
                                                    <form method="POST"
                                                          action="{{ route('archive.manage.transfer.archive', $row->archive) }}"
                                                          class="d-inline m-0">
                                                        @csrf
                                                        <input type="hidden" name="action" value="now">
                                                        <button class="btn btn-sm btn-brand">Transfer now</button>
                                                    </form>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Files that would not go ─────────────────────── --}}
            @if ($failedCount > 0)
                <div class="arc-card p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="h6 mb-0">
                            Would not transfer
                            <span class="arc-muted fw-normal">({{ number_format($failedCount) }})</span>
                        </h2>
                        <form method="POST" action="{{ route('archive.manage.transfer.retry') }}" class="m-0">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">Retry all</button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead><tr><th>Archive</th><th>File</th><th>Why</th></tr></thead>
                            <tbody>
                                @foreach ($failed as $file)
                                    <tr>
                                        <td class="arc-muted small">{{ $file->archive?->slug }}</td>
                                        <td class="small text-break">{{ $file->downloadName() }}</td>
                                        <td class="small text-break" style="color:var(--amber)">{{ $file->transfer_error }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ── Recent runs ─────────────────────────────────── --}}
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Recent runs</h2>

                @if ($runs->isEmpty())
                    <p class="arc-muted small mb-0">The worker has not moved anything yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead><tr><th>Started</th><th>Files</th><th>Moved</th><th>Speed</th><th>Note</th></tr></thead>
                            <tbody>
                                @foreach ($runs as $run)
                                    <tr>
                                        <td class="arc-muted small">{{ $run->started_at?->format('Y-m-d H:i') }}</td>
                                        <td class="small">{{ number_format($run->files_done) }}@if ($run->files_failed)<span class="text-danger"> +{{ $run->files_failed }} failed</span>@endif</td>
                                        <td class="small">{{ $human((int) $run->bytes_done) }}</td>
                                        <td class="small">{{ $run->avg_mbps ? $run->avg_mbps.' MB/s' : '—' }}</td>
                                        <td class="arc-muted small">{{ $run->note }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @endif
@endsection
