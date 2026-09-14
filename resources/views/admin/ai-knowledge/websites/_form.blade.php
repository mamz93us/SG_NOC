@php
    $isEdit = $source->exists;
@endphp

<div class="row g-3">
    <div class="col-md-7">
        <label for="webName" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="webName" name="name" maxlength="200" required class="form-control"
               value="{{ old('name', $source->name) }}" placeholder="e.g. Ministry of Human Resources — Labor Law">
    </div>
    <div class="col-md-5">
        <label for="webCategory" class="form-label fw-semibold">Category</label>
        <input type="text" id="webCategory" name="category" maxlength="50" class="form-control"
               value="{{ old('category', $source->category) }}" placeholder="Left empty: the AI files each page">
    </div>

    <div class="col-12">
        <label for="webStart" class="form-label fw-semibold">Start address <span class="text-danger">*</span></label>
        <input type="url" id="webStart" name="start_url" maxlength="2048" required class="form-control"
               value="{{ old('start_url', $source->start_url) }}" placeholder="https://www.example.com/policies/">
    </div>

    <div class="col-12">
        <label for="webScope" class="form-label fw-semibold">Part of the site to follow</label>
        <input type="url" id="webScope" name="scope_url" maxlength="2048" class="form-control"
               value="{{ old('scope_url', $isEdit ? $source->scope_url : '') }}" placeholder="Left empty: the folder the start address is in">
        <div class="form-text">Only links under this address are read, so a policies section does not pull in a whole site.</div>
    </div>

    <div class="col-md-4">
        <label for="webMaxPages" class="form-label fw-semibold">Most pages</label>
        <input type="number" id="webMaxPages" name="max_pages" min="1" max="500" required class="form-control"
               value="{{ old('max_pages', $source->max_pages ?? 50) }}">
    </div>
    <div class="col-md-4">
        <label for="webMaxDepth" class="form-label fw-semibold">Clicks from the start</label>
        <input type="number" id="webMaxDepth" name="max_depth" min="0" max="5" required class="form-control"
               value="{{ old('max_depth', $source->max_depth ?? 2) }}">
    </div>
    <div class="col-md-4">
        <label for="webRefresh" class="form-label fw-semibold">Read again every</label>
        <div class="input-group">
            <input type="number" id="webRefresh" name="refresh_days" min="0" max="90" required class="form-control"
                   value="{{ old('refresh_days', $source->refresh_days ?? 7) }}">
            <span class="input-group-text">days</span>
        </div>
        <div class="form-text">0: only when Read now is pressed.</div>
    </div>

    <div class="col-md-4">
        <label for="webAudience" class="form-label fw-semibold">Audience</label>
        <select name="audience" id="webAudience" class="form-select">
            <option value="all" @selected(old('audience', $source->audience ?? 'all') === 'all')>Everyone</option>
            <option value="branch" @selected(old('audience', $source->audience) === 'branch')>One branch</option>
            <option value="department" @selected(old('audience', $source->audience) === 'department')>One department</option>
        </select>
    </div>
    <div class="col-md-8" id="webBranchWrap" hidden>
        <label for="webBranch" class="form-label fw-semibold">Branch</label>
        <select name="audience_branch_id" id="webBranch" class="form-select">
            <option value="">Choose…</option>
            @foreach($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) old('audience_branch_id', $source->audience_branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-8" id="webDeptWrap" hidden>
        <label for="webDept" class="form-label fw-semibold">Department</label>
        <select name="audience_department_id" id="webDept" class="form-select">
            <option value="">Choose…</option>
            @foreach($departments as $department)
                <option value="{{ $department->id }}" @selected((string) old('audience_department_id', $source->audience_department_id) === (string) $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-12">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="webPublish" name="publish" value="1"
                   @checked(old('publish', $isEdit ? $source->publish : true))>
            <label class="form-check-label fw-semibold" for="webPublish">Publish pages as they are read</label>
            <div class="form-text">Off, each page waits as a draft article to be checked and published by hand.</div>
        </div>
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" role="switch" id="webInternal" name="allow_internal" value="1"
                   @checked(old('allow_internal', $source->allow_internal))>
            <label class="form-check-label fw-semibold" for="webInternal">Allow internal addresses</label>
            <div class="form-text">Only for a company intranet site. The NOC can reach every internal system, so leave this off for anything public.</div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const audience = document.getElementById('webAudience');
    const branchWrap = document.getElementById('webBranchWrap');
    const deptWrap = document.getElementById('webDeptWrap');

    function sync() {
        branchWrap.hidden = audience.value !== 'branch';
        deptWrap.hidden = audience.value !== 'department';
    }

    audience.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
