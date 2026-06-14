@if($f->status === \App\Models\DownloadFile::STATUS_STORED)
    <span class="badge bg-success-subtle text-success-emphasis">Stored</span>
@elseif($f->status === \App\Models\DownloadFile::STATUS_FETCHING)
    @php
        $total = (int) $f->download_total_bytes;
        $recv = (int) $f->download_received_bytes;
        $pct = $total > 0 ? min(100, (int) floor($recv / $total * 100)) : null;
        $uploading = $total > 0 && $recv >= $total;
    @endphp
    <span class="badge bg-primary-subtle text-primary-emphasis">{{ $uploading ? 'Uploading to Azure…' : 'Fetching…' }}</span>
    <div class="progress mt-1" style="height:6px; min-width:160px;">
        <div class="progress-bar {{ $pct === null || $uploading ? 'progress-bar-striped progress-bar-animated' : '' }}"
             role="progressbar" style="width:{{ $pct ?? 100 }}%;"></div>
    </div>
    <div class="small text-muted js-progress-text">
        @if($total > 0)
            {{ \App\Models\DownloadFile::formatBytes($recv) }} / {{ \App\Models\DownloadFile::formatBytes($total) }}
            @if($pct !== null) ({{ $pct }}%) @endif
        @else
            starting…
        @endif
    </div>
@elseif($f->status === \App\Models\DownloadFile::STATUS_PENDING)
    <span class="badge bg-warning-subtle text-warning-emphasis">Pending</span>
@else
    <span class="badge bg-danger-subtle text-danger-emphasis" title="{{ $f->error }}">Failed</span>
    @if($f->error)<div class="small text-danger mt-1">{{ \Illuminate\Support\Str::limit($f->error, 80) }}</div>@endif
    @can('manage-downloads')
        @if($f->source === \App\Models\DownloadFile::SOURCE_URL)
        <form action="{{ route('admin.downloads.retry', $f) }}" method="POST" class="d-inline">
            @csrf
            <button class="btn btn-sm btn-link p-0 text-decoration-none">Retry</button>
        </form>
        @endif
    @endcan
@endif
