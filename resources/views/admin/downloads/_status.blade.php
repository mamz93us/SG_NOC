@php($s = $f->status)
@if($s === \App\Models\DownloadFile::STATUS_STORED)
    <span class="badge bg-success-subtle text-success-emphasis">Stored</span>
@elseif($s === \App\Models\DownloadFile::STATUS_FETCHING)
    <span class="badge bg-primary-subtle text-primary-emphasis">Fetching…</span>
@elseif($s === \App\Models\DownloadFile::STATUS_PENDING)
    <span class="badge bg-warning-subtle text-warning-emphasis">Pending</span>
@else
    <span class="badge bg-danger-subtle text-danger-emphasis" title="{{ $f->error }}">Failed</span>
@endif
