@extends('layouts.admin')

@section('title', $archive->displayName())

@section('content')
    @include('admin.archive._styles')

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('admin.archive.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Manage
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">{{ $archive->displayName() }}</span>
    </div>

    @unless ($archive->readable)
        <div class="alert alert-warning py-2">
            <i class="bi bi-lock"></i> {{ $archive->unreadable_reason }}
            Nothing is mirrored, transferred or read from it.
        </div>
    @endunless

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Mirror</h2>
                <dl class="mb-0 small">
                    <dt class="arc-muted fw-normal">ArcMate project</dt>
                    <dd class="mb-2">{{ $archive->arcmate_folder ?: '—' }}</dd>

                    <dt class="arc-muted fw-normal">Database</dt>
                    <dd class="mb-2">{{ $archive->arcmate_database ?: '—' }}</dd>

                    <dt class="arc-muted fw-normal">Mode</dt>
                    <dd class="mb-2">{{ $modes[$archive->mode] ?? $archive->mode }}</dd>

                    <dt class="arc-muted fw-normal">Copied so far</dt>
                    <dd class="mb-2">
                        {{ number_format($archive->document_count) }} documents,
                        {{ number_format($archive->file_count) }} files
                        @if ($archive->backfill_done_at)
                            <span class="badge badge-soft ms-1">caught up</span>
                        @endif
                    </dd>

                    {{-- The watermarks are the sync's whole memory: if a backfill has
                         to be re-run, these are what gets reset. Worth showing. --}}
                    <dt class="arc-muted fw-normal">Watermarks</dt>
                    <dd class="mb-2 arc-muted">
                        documents {{ number_format($archive->last_doc_arc_id) }},
                        files {{ number_format($archive->last_file_arc_id) }}
                    </dd>

                    @if ($sampleFile)
                        <dt class="arc-muted fw-normal">A file, as resolved</dt>
                        <dd class="mb-0 text-break arc-muted">{{ $sampleFile->path }}</dd>
                    @endif
                </dl>
            </div>

            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Fields</h2>
                @if ($archive->fields->isEmpty())
                    <p class="arc-muted small mb-0">No fields — nothing to search on.</p>
                @else
                    <table class="table arc-table mb-0">
                        <thead><tr><th>Label</th><th>Type</th><th>ArcMate</th></tr></thead>
                        <tbody>
                            @foreach ($archive->fields as $field)
                                <tr>
                                    <td>{{ $field->label() }}</td>
                                    <td class="arc-muted small">{{ \App\Models\Archive\ArchiveField::TYPES[$field->type] ?? $field->type }}</td>
                                    <td class="arc-muted small">{{ $field->arcmate_column ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Add a field ArcMate does not have.

                    Worth saying on screen why this is safe on a MIRRORED archive:
                    the sync copies values by ArcMate column, so a field with no
                    column is invisible to it. Without that line the obvious
                    assumption is that the next sync will wipe anything added here,
                    and nobody would use it. --}}
                <hr class="my-3">
                <h3 class="h6 mb-1">Add a field</h3>
                <p class="arc-muted small mb-3">
                    ArcMate's own fields are above. A field added here is this portal's:
                    the ArcMate sync never writes it and never clears it, because it maps
                    values by ArcMate column and this one has none. Fill it in by hand, or
                    let an <a href="{{ route('admin.archive.ai') }}">AI fill batch</a>
                    read it off the scans and propose values for review.
                </p>

                <form method="POST" action="{{ route('admin.archive.fields.store', $archive) }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small arc-muted mb-1">Name</label>
                            <input class="form-control form-control-sm" name="label" required maxlength="120"
                                   value="{{ old('label') }}" placeholder="Customer">
                        </div>
                        <div class="col-5">
                            <label class="form-label small arc-muted mb-1">Type</label>
                            <select class="form-select form-select-sm" name="type">
                                @foreach (\App\Models\Archive\ArchiveField::TYPES as $value => $name)
                                    <option value="{{ $value }}" @selected(old('type') === $value)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">
                                What the AI should look for <span class="arc-muted">(optional)</span>
                            </label>
                            <input class="form-control form-control-sm" name="ai_hint" maxlength="500"
                                   value="{{ old('ai_hint') }}"
                                   placeholder="The company the invoice is addressed to, near the top">
                            <div class="form-text small">
                                Goes to the AI with the field when it reads a scan. A field with no hint
                                is filled from its name alone, which is enough for an obvious one and not
                                for a form with three names on it.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">
                                Choices, one per line <span class="arc-muted">(list fields only)</span>
                            </label>
                            <textarea class="form-control form-control-sm" name="options" rows="2"
                                      maxlength="2000" placeholder="Invoice&#10;Credit note&#10;Delivery note">{{ old('options') }}</textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-brand">Add field</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── Who may read it ────────────────────────────────────── --}}
        <div class="col-lg-7">
            <div class="arc-card p-3">
                <h2 class="h6 mb-1">Access</h2>
                <p class="arc-muted small mb-3">
                    Access is per archive. Being able to sign in to the portal is not access to anything here.
                </p>

                <form method="POST" action="{{ route('admin.archive.members.store', $archive) }}" class="mb-3">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small arc-muted mb-1">Work e-mail</label>
                            <input class="form-control form-control-sm" name="email" type="email" required
                                   placeholder="name@samirgroup.com">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small arc-muted mb-1">May also</label>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($abilities as $key => $label)
                                    @continue($key === 'can_view')
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="checkbox" name="abilities[]"
                                               value="{{ $key }}" id="ability-{{ $key }}">
                                        <label class="form-check-label small" for="ability-{{ $key }}">
                                            {{ \Illuminate\Support\Str::of($label)->before(' (')->before(',') }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-2 text-end">
                            <button class="btn btn-brand btn-sm w-100">Add</button>
                        </div>
                    </div>
                </form>

                @if ($archive->members->isEmpty())
                    <p class="arc-muted small mb-0">Nobody has been given access yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead><tr><th>Person</th><th>May</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($archive->members as $member)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $member->user?->name ?? '—' }}</div>
                                            <div class="arc-muted small">{{ $member->user?->email }}</div>
                                        </td>
                                        <td class="arc-muted small">{{ implode(', ', $member->grantedLabels()) }}</td>
                                        <td class="text-end">
                                            <form method="POST"
                                                  action="{{ route('admin.archive.members.destroy', [$archive, $member]) }}"
                                                  class="m-0"
                                                  onsubmit="return confirm('Remove access for {{ $member->user?->email }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
