@extends('layouts.admin')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">{{ $pair->label() }}</h4>
            <p class="text-muted small mb-0">
                Test calls from <strong>{{ $pair->caller?->name }}</strong>
                (registering to {{ $pair->caller?->sip_server }} as {{ $pair->caller?->sip_user }})
                to <strong>{{ $pair->dest?->name }}</strong>'s IVR extension {{ $pair->dest?->ivr_ext }}.
            </p>
        </div>
        <div class="text-end">
            @can('manage-voice-mesh')
                <form method="POST" action="{{ route('admin.network.voice-mesh.pair.retry', $pair) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-primary" {{ $pendingSweep ? 'disabled' : '' }}>
                        <i class="bi bi-arrow-repeat me-1"></i>Retry this leg
                    </button>
                </form>
            @endcan
            <a href="{{ route('admin.network.voice-mesh.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to the mesh
            </a>
            @can('manage-voice-mesh')
                <div class="small text-muted mt-2" style="max-width: 30ch;">
                    Retries one call rather than the whole mesh; the prober picks it up within a minute.
                </div>
            @endcan
        </div>
    </div>

    @if ($pendingSweep)
        <div class="alert alert-info py-2">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ $pendingSweep['scope']
                ? 'Retry of '.str_replace('>', ' → ', $pendingSweep['scope']).' requested '.$pendingSweep['requested'].'.'
                : 'Full sweep requested '.$pendingSweep['requested'].'.' }}
            Reload in a minute or two to see the result.
        </div>
    @endif

    @if ($events->isNotEmpty())
        <div class="alert alert-warning">
            <div class="fw-semibold mb-2">Open alerts touching this leg</div>
            @foreach ($events as $event)
                <div class="small">
                    <span class="badge bg-{{ $event->severity === 'critical' ? 'danger' : 'warning text-dark' }}">
                        {{ $event->severity }}
                    </span>
                    <strong>{{ $event->title }}</strong> — {{ $event->message }}
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small text-uppercase">Current</div>
                <span class="badge {{ $pair->statusBadgeClass() }} fs-6">{{ $pair->statusLabel() }}</span>
                <div class="small text-muted mt-1">{{ $pair->last_checked_at?->diffForHumans() ?? 'never tested' }}</div>
            </div></div>
        </div>
        @foreach ($rates as $window => $rate)
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body py-3">
                    <div class="text-muted small text-uppercase">Success, {{ $window }}</div>
                    <div class="fs-3 fw-semibold">{{ $rate === null ? '—' : $rate.'%' }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    @if ($pair->last_reason)
        <div class="card mb-3"><div class="card-body py-3">
            <div class="text-muted small text-uppercase mb-1">Last reported reason</div>
            <code>{{ $pair->last_reason }}</code>
            @if ($pair->last_rx_pkt !== null)
                <div class="small text-muted mt-2">
                    {{ $pair->last_rx_pkt }} RTP packets received ·
                    recorded {{ $pair->last_duration_sec }}s against a {{ $pair->last_reference_sec }}s reference
                    @if ($pair->driftPct() !== null)
                        ({{ round($pair->driftPct(), 1) }}% drift)
                    @endif
                </div>
            @endif
        </div></div>
    @endif

    <div class="card mb-3">
        <div class="card-header fw-semibold">Recent sweeps <span class="text-muted small fw-normal">(oldest left)</span></div>
        <div class="card-body">
            @if ($checks->isEmpty())
                <p class="text-muted mb-0">No history yet.</p>
            @else
                <div class="vm-strip mb-3">
                    @foreach ($checks as $check)
                        <span class="{{ $check->ok ? 'ok' : 'fail' }}"
                              title="{{ $check->checked_at->format('D d M H:i') }} — {{ $check->ok ? 'OK' : $check->reason }}"></span>
                    @endforeach
                </div>
                {{-- Duration against the reference: a slow drift here is how a
                     replaced or truncated IVR prompt shows up before it ever
                     breaks the tolerance. --}}
                <div class="text-muted small text-uppercase mb-1">Prompt duration vs reference</div>
                <div class="vm-spark">
                    @php
                        $reference = (float) ($checks->last()->reference_sec ?: 1);
                        $ceiling = max($reference * 1.6, $checks->max('duration_sec') ?: $reference);
                    @endphp
                    @foreach ($checks as $check)
                        @php $h = $ceiling > 0 ? min(100, ((float) $check->duration_sec / $ceiling) * 100) : 0; @endphp
                        <span style="height: {{ $h }}%"
                              class="{{ $check->ok ? 'ok' : 'fail' }}"
                              title="{{ $check->checked_at->format('D d M H:i') }} — {{ $check->duration_sec }}s"></span>
                    @endforeach
                    <div class="vm-refline" style="bottom: {{ $ceiling > 0 ? ($reference / $ceiling) * 100 : 0 }}%"></div>
                </div>
                <div class="small text-muted mt-1">Dashed line is the {{ $reference }}s reference prompt.</div>
            @endif
        </div>
    </div>

    @if ($reasons->isNotEmpty())
        <div class="card">
            <div class="card-header fw-semibold">Why it failed, last 30 days</div>
            <table class="table table-sm mb-0">
                <tbody>
                    @foreach ($reasons as $reason)
                        <tr>
                            <td><code class="small">{{ $reason->reason ?: '(no reason given)' }}</code></td>
                            <td class="text-end text-muted" style="width: 6rem;">{{ $reason->cnt }}×</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="card-footer small text-muted">
                &ldquo;never reached CONFIRMED&rdquo; is signalling — registration refused, or routing never
                reached the IVR.
                &ldquo;no RTP media received&rdquo; is one-way audio or a blocked RTP range, while
                &ldquo;packets arrived but no audible audio&rdquo; means the media path works and the audio
                doesn't — usually a codec both ends agreed on but neither produced.
                &ldquo;prompt ran Xs vs reference&rdquo; is a length problem: too long means the IVR isn't
                hanging up on its own, too short means a truncated or wrong prompt.
                &ldquo;does not match the reference prompt&rdquo; means something of about the right length
                answered but it isn't the prompt — ringback, hold music or a different recording.
                <br>
                A leg is only failed after a re-test disagrees, so a red cell here has been confirmed by at
                least two calls; <code>journalctl -u voice-mesh</code> shows how many attempts each took.
            </div>
        </div>
    @endif
</div>

<style>
    .vm-strip { display: flex; gap: 2px; flex-wrap: wrap; }
    .vm-strip span { width: 8px; height: 22px; border-radius: 2px; background: var(--bs-secondary-bg); }
    .vm-strip span.ok { background: var(--bs-success); }
    .vm-strip span.fail { background: var(--bs-danger); }
    .vm-spark { position: relative; display: flex; align-items: flex-end; gap: 2px; height: 90px;
                border-bottom: 1px solid var(--bs-border-color); }
    .vm-spark span { width: 8px; background: var(--bs-success); border-radius: 2px 2px 0 0; min-height: 1px; }
    .vm-spark span.fail { background: var(--bs-danger); }
    .vm-refline { position: absolute; left: 0; right: 0; border-top: 1px dashed var(--bs-secondary-color); }
</style>
@endsection
