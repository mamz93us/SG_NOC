@php($s = $f->status)
@if($s === \App\Models\DownloadFile::STATUS_STORED)
    <span class="badge bg-success-subtle text-success-emphasis">Stored</span>
@elseif($s === \App\Models\DownloadFile::STATUS_FETCHING)
    <span class="badge bg-primary-subtle text-primary-emphasis">Fetching…</span>
@elseif($s === \App\Models\DownloadFile::STATUS_PENDING)
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
