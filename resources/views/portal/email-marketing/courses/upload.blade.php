@extends('layouts.portal')

@section('title', 'Upload certificates — '.$course->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    <div class="mb-3">
        <a href="{{ route('portal.marketing.courses.show', $course) }}" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to {{ $course->name }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if (session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Upload certificates</strong> — for course <em>{{ $course->name }}</em></div>
        <form method="POST" action="{{ route('portal.marketing.courses.upload.store', $course) }}" enctype="multipart/form-data">
            @csrf
            <div class="card-body">
                <p class="text-muted small">
                    Filenames must be the recipient's email plus a <code>.pdf</code>, <code>.jpg</code>, <code>.jpeg</code>
                    or <code>.png</code> extension &mdash; e.g. <code>ahmed&#64;samirgroup.com.pdf</code>.
                    Files whose email doesn't match any active employee are kept as <strong>orphans</strong>
                    and can be linked manually from the course page.
                    Re-uploading the same email replaces the file but keeps the existing link.
                </p>
                <input type="file" name="files[]" multiple required class="form-control"
                       accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                <small class="text-muted">Max 500 files per upload, 10 MB per file.</small>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
            </div>
        </form>
    </div>

    @if ($report)
        <div class="card shadow-sm">
            <div class="card-header">
                <strong>Upload report</strong>
                <span class="badge bg-success ms-2">{{ $report['imported'] }} imported</span>
                <span class="badge bg-info ms-1">{{ $report['replaced'] }} replaced</span>
                <span class="badge bg-warning text-dark ms-1">{{ $report['orphaned'] }} orphaned</span>
                <span class="badge bg-danger ms-1">{{ $report['rejected'] }} rejected</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>File</th><th>Status</th><th>Detail</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($report['items'] as $row)
                        <tr>
                            <td><code>{{ $row['filename'] }}</code></td>
                            <td>
                                @switch ($row['status'])
                                    @case ('imported')  <span class="badge bg-success">Imported</span>  @break
                                    @case ('replaced')  <span class="badge bg-info">Replaced</span>     @break
                                    @case ('orphaned')  <span class="badge bg-warning text-dark">Orphaned</span> @break
                                    @case ('rejected')  <span class="badge bg-danger">Rejected</span>   @break
                                    @default            <span class="badge bg-secondary">{{ $row['status'] }}</span>
                                @endswitch
                            </td>
                            <td><small>{{ $row['message'] ?? '' }}</small></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
