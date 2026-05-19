@extends('layouts.portal')

@section('title', 'Send certificates — '.$course->name)

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
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="alert alert-info">
        <strong>{{ $eligibleCount }}</strong> recipient{{ $eligibleCount === 1 ? '' : 's' }} eligible
        (linked employees only — orphans are skipped).
        {{ $alreadySent }} already received a copy.
        Use the merge tag <code>&#123;&#123;certificate_url&#125;&#125;</code> in your template to embed each recipient's personal link.
    </div>

    <form method="POST" action="{{ route('portal.marketing.courses.send.store', $course) }}" class="card shadow-sm">
        @csrf

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Campaign name</label>
                    <input type="text" name="name" class="form-control" required
                           value="{{ old('name', $course->name.' — '.now()->format('Y-m-d')) }}">
                    <small class="text-muted">Internal label for the marketing campaigns list.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Send at</label>
                    <input type="datetime-local" name="scheduled_at" class="form-control"
                           value="{{ old('scheduled_at') }}">
                    <small class="text-muted">Leave blank to send immediately (next minute).</small>
                </div>

                <div class="col-12">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" required
                           value="{{ old('subject', $course->default_subject) }}">
                </div>

                <div class="col-12">
                    <label class="form-label">Preview text</label>
                    <input type="text" name="preview_text" class="form-control"
                           value="{{ old('preview_text') }}">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email template</label>
                    <select name="email_template_id" class="form-select" required>
                        <option value="">— Select a template —</option>
                        @foreach ($templates as $t)
                            <option value="{{ $t->id }}" @selected(old('email_template_id', $course->default_template_id) == $t->id)>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">From name</label>
                    <input type="text" name="from_name" class="form-control" required
                           value="{{ old('from_name', $course->default_from_name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">From email</label>
                    <input type="email" name="from_email" class="form-control" required
                           value="{{ old('from_email', $course->default_from_email) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reply-to (optional)</label>
                    <input type="email" name="reply_to" class="form-control"
                           value="{{ old('reply_to') }}">
                </div>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.courses.show', $course) }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-success" @if ($eligibleCount === 0) disabled @endif>
                <i class="bi bi-send me-1"></i>Schedule send
            </button>
        </div>
    </form>
</div>
@endsection
