@extends('layouts.admin')

@section('title', 'Create Ticket')

@section('content')
@php
    $configured = $settings->noc_ticket_api_enabled
        && $settings->noc_ticket_api_url
        && $settings->nocTicketApiKey();
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-ticket-perforated-fill me-2 text-primary"></i>Create Ticket</h4>
        <small class="text-muted">Raise a ticket in the IT ticketing system without leaving the NOC</small>
    </div>
    @can('view-tickets')
        <a href="{{ route('admin.tickets.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history me-1"></i>Submission History
        </a>
    @endcan
</div>

@if (session('error'))
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-octagon-fill fs-5"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

@if (! $configured)
    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div class="small">
            The ticketing API is not enabled or not fully configured.
            @can('manage-settings')
                Set the endpoint URL and X-API-Key under
                <a href="{{ route('admin.settings.index') }}#noc-ticketing" class="alert-link">Admin &rarr; Settings &rarr; Create Ticket API</a>.
            @else
                Ask an administrator to configure it in Settings.
            @endcan
        </div>
    </div>
@endif

@if (! $catalog->isConfigured())
    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-list-ul fs-5"></i>
        <div class="small">
            The ticket catalog is empty, so there are no categories, types or priorities to choose from.
            Categories normally come straight from the ticketing API's <code>getCategories</code> endpoint;
            when that call fails the hand-maintained JSON in Settings is used instead.
            @if($catalogError)
                <div class="mt-1 font-monospace">Last API error: {{ $catalogError }}</div>
            @endif
            @can('manage-settings')
                Check it under
                <a href="{{ route('admin.settings.index') }}#noc-ticketing" class="alert-link">Admin &rarr; Settings &rarr; Create Ticket API</a>.
            @else
                Ask an administrator to check it.
            @endcan
        </div>
    </div>
@elseif (! $catalog->isFromApi())
    <div class="alert alert-secondary d-flex gap-2">
        <i class="bi bi-hdd-network fs-5"></i>
        <div class="small">
            Showing the catalog saved in Settings &mdash; the ticketing API's category list could not be reached.
            @if($catalogError)
                <span class="font-monospace">({{ $catalogError }})</span>
            @endif
            The IDs below may be out of date.
        </div>
    </div>
@endif

@if (! $myIdentity && ! $canFileForOthers)
    <div class="alert alert-danger d-flex gap-2">
        <i class="bi bi-person-x-fill fs-5"></i>
        <div class="small">
            No Azure identity is on file for <strong>{{ $myEmail }}</strong>. The ticketing API identifies
            the requester by Azure object id, so a ticket cannot be raised for you until identity sync
            has picked your account up.
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.tickets.store') }}" enctype="multipart/form-data" id="ticketForm">
    @csrf
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-card-text me-1 text-primary"></i>Ticket details
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" maxlength="255" required
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title') }}"
                               placeholder="Short summary, e.g. Laptop will not power on">
                        @error('title') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                        <textarea name="description" rows="7" maxlength="5000" required
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="What is happening, what was tried, which device / location.">{{ old('description') }}</textarea>
                        @error('description') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category_id" id="categorySelect" required
                                    class="form-select @error('category_id') is-invalid @enderror">
                                <option value="">Choose…</option>
                                @foreach($catalog->categories as $cat)
                                    <option value="{{ $cat['id'] }}"
                                            @selected((string) old('category_id') === (string) $cat['id'])>
                                        {{ $cat['name'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sub-category <span class="text-danger">*</span></label>
                            <select name="subcategory_id" id="subcategorySelect" required
                                    class="form-select @error('subcategory_id') is-invalid @enderror">
                                <option value="">Choose a category first…</option>
                            </select>
                            @error('subcategory_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select name="type_id" id="typeSelect" required class="form-select @error('type_id') is-invalid @enderror">
                                <option value="">Choose…</option>
                                @foreach($catalog->types as $type)
                                    <option value="{{ $type['id'] }}"
                                            @selected((string) old('type_id') === (string) $type['id'])>
                                        {{ $type['name'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('type_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            <div class="form-text d-none" data-suggested="type">
                                <i class="bi bi-magic me-1"></i>Set from the sub-category — change it if it is wrong.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Priority <span class="text-danger">*</span></label>
                            <select name="priority_id" id="prioritySelect" required class="form-select @error('priority_id') is-invalid @enderror">
                                <option value="">Choose…</option>
                                @foreach($catalog->priorities as $prio)
                                    <option value="{{ $prio['id'] }}"
                                            @selected((string) old('priority_id') === (string) $prio['id'])>
                                        {{ $prio['name'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('priority_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            <div class="form-text d-none" data-suggested="priority">
                                <i class="bi bi-magic me-1"></i>Set from the sub-category — change it if it is wrong.
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div>
                        <label class="form-label fw-semibold">Attachment</label>
                        <input type="file" name="attachment"
                               class="form-control @error('attachment') is-invalid @enderror">
                        <div class="form-text">Optional. One file, up to 20&nbsp;MB — screenshot, photo or log.</div>
                        @error('attachment') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-person-badge me-1 text-primary"></i>Requester
                </div>
                <div class="card-body">
                    @if($canFileForOthers)
                        <label class="form-label fw-semibold">Raise on behalf of</label>
                        <input type="email" name="requester_email" list="directoryList" required
                               autocomplete="off"
                               class="form-control @error('requester_email') is-invalid @enderror"
                               value="{{ old('requester_email', $myIdentity?->mail ?: $myIdentity?->user_principal_name ?: $myEmail) }}"
                               placeholder="name@samirgroup.com">
                        <datalist id="directoryList">
                            @foreach($directory as $person)
                                @php $addr = $person->mail ?: $person->user_principal_name; @endphp
                                @if($addr)
                                    <option value="{{ $addr }}">{{ $person->display_name }}{{ $person->department ? ' — ' . $person->department : '' }}</option>
                                @endif
                            @endforeach
                        </datalist>
                        @error('requester_email') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        <div class="form-text">
                            Start typing a name or address. The person must exist in Azure — the API
                            identifies the requester by object id, not by email.
                        </div>
                    @else
                        <input type="hidden" name="requester_email"
                               value="{{ $myIdentity?->mail ?: $myIdentity?->user_principal_name ?: $myEmail }}">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-circle fs-4 text-secondary"></i>
                            <div>
                                <div class="fw-semibold">{{ $myIdentity?->display_name ?: auth()->user()->name }}</div>
                                <div class="small text-muted font-monospace">{{ $myIdentity?->mail ?: $myEmail }}</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">Tickets are raised in your own name.</div>
                    @endif
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100"
                            @disabled(! $configured || ! $catalog->isConfigured())>
                        <i class="bi bi-send-fill me-1"></i>Submit Ticket
                    </button>
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-link w-100 mt-2 text-muted">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

@php
    // id => [subcategories] so the second select can be filled without a round trip.
    $subMap = collect($catalog->categories)
        ->mapWithKeys(fn ($c) => [$c['id'] => $c['subcategories']])
        ->all();
@endphp

<script>
(function () {
    const subsByCategory = @json($subMap);
    const previousSub    = @json(old('subcategory_id'));
    const hadOldInput    = @json(old('type_id') !== null);
    const categorySelect = document.getElementById('categorySelect');
    const subSelect      = document.getElementById('subcategorySelect');
    const typeSelect     = document.getElementById('typeSelect');
    const prioritySelect = document.getElementById('prioritySelect');

    if (!categorySelect || !subSelect) return;

    function fillSubcategories() {
        const subs = subsByCategory[categorySelect.value] || [];
        subSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = categorySelect.value
            ? (subs.length ? 'Choose…' : 'No sub-categories defined')
            : 'Choose a category first…';
        subSelect.appendChild(placeholder);

        subs.forEach(function (sub) {
            const option = document.createElement('option');
            option.value = sub.id;
            option.textContent = sub.name;
            if (sub.type_id !== null && sub.type_id !== undefined) {
                option.dataset.typeId = sub.type_id;
            }
            if (sub.priority_id !== null && sub.priority_id !== undefined) {
                option.dataset.priorityId = sub.priority_id;
            }
            if (String(previousSub) === String(sub.id)) option.selected = true;
            subSelect.appendChild(option);
        });
    }

    // The API pairs a type and a priority with every sub-category, so picking
    // one answers both. Still editable — this is a default, not a lock.
    function applySubcategoryDefaults() {
        const chosen = subSelect.selectedOptions[0];

        [[typeSelect, 'typeId', 'type'], [prioritySelect, 'priorityId', 'priority']]
            .forEach(function ([select, key, hintName]) {
                if (!select) return;

                const hint = document.querySelector('[data-suggested="' + hintName + '"]');
                const value = chosen ? chosen.dataset[key] : undefined;
                const known = value !== undefined
                    && Array.from(select.options).some(o => o.value === String(value));

                if (known) {
                    select.value = String(value);
                }
                if (hint) hint.classList.toggle('d-none', !known);
            });
    }

    categorySelect.addEventListener('change', function () {
        fillSubcategories();
        applySubcategoryDefaults();
    });
    subSelect.addEventListener('change', applySubcategoryDefaults);

    fillSubcategories();
    // A redisplayed form keeps whatever the user had chosen; a fresh one takes
    // the defaults for the sub-category it opened with.
    if (!hadOldInput) applySubcategoryDefaults();
})();
</script>
@endsection
