@extends('layouts.admin')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-clock-history me-2"></i>Voice mesh run log</h4>
            <p class="text-muted small mb-0">Every sweep the prober has reported.</p>
        </div>
        <a href="{{ route('admin.network.voice-mesh.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to the mesh
        </a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Received</th><th>Reported</th><th>Runner</th>
                        <th>Result</th><th>Legs</th><th>Unknown codes</th><th>Source</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="text-nowrap">{{ $run->received_at->format('D d M H:i') }}</td>
                            <td class="text-nowrap small text-muted">{{ $run->reported_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="small">{{ $run->runner_name }}<br>
                                <span class="text-muted">{{ $run->probe_version }}</span></td>
                            <td>
                                <span class="badge bg-{{ $run->ok ? 'success' : 'danger' }}">
                                    {{ $run->ok ? 'clean' : 'failures' }}
                                </span>
                            </td>
                            <td class="small">{{ $run->pairs_ok }}/{{ $run->pairs_total }}
                                @if ($run->okPercent() !== null)
                                    <span class="text-muted">({{ $run->okPercent() }}%)</span>
                                @endif
                            </td>
                            <td class="small text-warning">
                                {{ $run->unknown_nodes ? implode(', ', $run->unknown_nodes) : '' }}
                            </td>
                            <td class="small font-monospace text-muted">{{ $run->source_ip }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.network.voice-mesh.run', $run) }}"
                                   class="btn btn-sm btn-outline-secondary">Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted p-3">
                            Nothing reported yet. If the prober is installed, check
                            <code>systemctl status voice-mesh-verify.timer</code> on this host.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent">{{ $runs->links() }}</div>
    </div>
</div>
@endsection
