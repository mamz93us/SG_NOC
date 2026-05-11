{{-- Chunked-upload widget for a manually-exported backup (AvePoint fallback / laptop archive). --}}
<form method="POST" action="{{ route('admin.offboarding.backup.upload', ['backup' => $backup]) }}"
      enctype="multipart/form-data" class="d-inline-flex align-items-center gap-2">
    @csrf
    <input type="file" name="archive" required class="form-control form-control-sm" style="max-width:240px">
    <button type="submit" class="btn btn-sm btn-warning">
        <i class="bi bi-upload me-1"></i>Upload
    </button>
</form>
