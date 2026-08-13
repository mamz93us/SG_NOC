@extends('layouts.admin')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">Sweep at {{ $run->received_at->format('D d M Y H:i') }}</h4>
            <p class="text-muted small mb-0">
                {{ $run->runner_name }} reported {{ $run->pairs_ok }}/{{ $run->pairs_total }} legs OK
                from {{ $run->source_ip }}.
            </p>
        </div>
        <a href="{{ route('admin.network.voice-mesh.runs') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>All runs
        </a>
    </div>

    @if ($run->unknown_nodes)
        <div class="alert alert-warning py-2 small">
            This sweep named branch codes with no matching entry:
            <strong>{{ implode(', ', $run->unknown_nodes) }}</strong>. Those legs were discarded.
        </div>
    @endif

    <div class="card">
        <div class="card-header fw-semibold">Legs</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>From</th><th>To</th><th>Dialled</th><th>Result</th>
                        <th class="text-end">RTP pkt</th><th class="text-end">Duration</th>
                        <th class="text-end">Reference</th><th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($results as $result)
                        <tr class="{{ $result->ok ? '' : 'table-danger' }}">
                            <td class="fw-semibold">{{ $result->caller_code }}</td>
                            <td class="fw-semibold">{{ $result->dest_code }}</td>
                            <td class="font-monospace small">{{ $result->dest_ext }}</td>
                            <td>
                                <span class="badge bg-{{ $result->ok ? 'success' : 'danger' }}">
                                    {{ $result->ok ? 'OK' : 'FAIL' }}
                                </span>
                            </td>
                            <td class="text-end small">{{ $result->rx_pkt }}</td>
                            <td class="text-end small">{{ $result->duration_sec }}s</td>
                            <td class="text-end small text-muted">{{ $result->reference_sec }}s</td>
                            <td class="small">{{ $result->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
