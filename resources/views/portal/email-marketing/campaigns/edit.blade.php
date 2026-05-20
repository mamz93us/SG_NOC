@extends('layouts.portal')

@section('title', $campaign->exists ? 'Edit campaign' : 'New campaign')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    @if (!empty($spamHits))
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Spam-trigger words in subject:</strong> {{ implode(', ', $spamHits) }}.
            Inbox placement may suffer.
        </div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $campaign->exists ? route('portal.marketing.campaigns.update', $campaign) : route('portal.marketing.campaigns.store') }}">
        @csrf
        @if ($campaign->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Campaign name (internal)</label>
                    <input type="text" name="name" class="form-control" required value="{{ old('name', $campaign->name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" required value="{{ old('subject', $campaign->subject) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Preview text</label>
                    <input type="text" name="preview_text" class="form-control" value="{{ old('preview_text', $campaign->preview_text) }}">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Sender (from email — admin allowlist)</label>
                    @if ($senders->isEmpty())
                        <div class="alert alert-warning py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            No allowed senders configured. Ask an admin to add one at
                            <strong>Admin → Marketing → Sender allowlist</strong>.
                        </div>
                        <input type="hidden" name="from_email" value="">
                        <input type="hidden" name="from_name" value="">
                    @else
                        <select id="sender-picker" name="from_email" class="form-select" required>
                            <option value="">Select a sender…</option>
                            @foreach ($senders as $s)
                                <option value="{{ $s->email }}"
                                        data-name="{{ $s->name }}"
                                        data-reply-to="{{ $s->reply_to }}"
                                        @selected(old('from_email', $campaign->from_email) === $s->email)>
                                    {{ $s->name }} &lt;{{ $s->email }}&gt;
                                    @if ($s->is_default) (default)@endif
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Picking auto-fills From name + Reply-to below.</small>
                    @endif
                </div>
                <div class="col-md-3">
                    <label class="form-label">From name (display)</label>
                    <input type="text" name="from_name" id="from_name" class="form-control" required
                           value="{{ old('from_name', $campaign->from_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reply-to (optional)</label>
                    <input type="email" name="reply_to" id="reply_to" class="form-control"
                           value="{{ old('reply_to', $campaign->reply_to) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Template</label>
                    <select name="email_template_id" class="form-select" required>
                        <option value="">Select template…</option>
                        @foreach ($templates as $t)
                            <option value="{{ $t->id }}" @selected(old('email_template_id', $campaign->email_template_id) == $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">List</label>
                    <select name="email_list_id" class="form-select">
                        <option value="">—</option>
                        @foreach ($lists as $l)
                            <option value="{{ $l->id }}" @selected(old('email_list_id', $campaign->email_list_id) == $l->id)>{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Or Segment (instead of List)</label>
                    <select name="email_segment_id" class="form-select">
                        <option value="">—</option>
                        @foreach ($segments as $s)
                            <option value="{{ $s->id }}" @selected(old('email_segment_id', $campaign->email_segment_id) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Pick either a list or a segment, not both.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Schedule (optional)</label>
                    <input type="datetime-local" name="scheduled_at" class="form-control"
                           value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="draft" @selected(old('status', $campaign->status ?: 'draft') === 'draft')>Draft</option>
                        <option value="scheduled" @selected(old('status', $campaign->status) === 'scheduled')>Scheduled</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.campaigns.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save campaign</button>
        </div>
    </form>
</div>

<script>
(function () {
    // Auto-populate from_name + reply_to when the marketing user picks a sender.
    const picker = document.getElementById('sender-picker');
    if (!picker) return;
    const nameInput  = document.getElementById('from_name');
    const replyInput = document.getElementById('reply_to');
    picker.addEventListener('change', function () {
        const opt = this.selectedOptions[0];
        if (!opt) return;
        const name    = opt.getAttribute('data-name')     || '';
        const replyTo = opt.getAttribute('data-reply-to') || '';
        // Overwrite name (most users want the admin-curated label),
        // only fill reply_to if blank so users can clear it intentionally.
        if (nameInput)  nameInput.value = name;
        if (replyInput && !replyInput.value.trim()) replyInput.value = replyTo;
    });
})();
</script>
@endsection
