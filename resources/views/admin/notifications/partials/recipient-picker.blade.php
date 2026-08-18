{{--
    Multi-user recipient picker.

    A checkbox list rather than a <select multiple>: a rule routinely names
    three or four people, and ctrl-clicking a native multi-select drops the
    whole selection on one mis-click.

    Expects: $uid (unique suffix), $users, $selectedIds (array of user ids).
--}}
@php $selectedIds = array_map('intval', (array) $selectedIds); @endphp

<div data-picker-id="{{ $uid }}">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <label class="form-label fw-semibold mb-0">Users</label>
        <span id="recipient_count_{{ $uid }}" class="badge {{ count($selectedIds) ? 'bg-primary' : 'bg-secondary' }}">
            {{ count($selectedIds) }} selected
        </span>
    </div>

    <div class="input-group input-group-sm mb-2">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" id="recipient_search_{{ $uid }}"
               placeholder="Filter by name or email…" autocomplete="off"
               oninput="filterRecipients('{{ $uid }}')">
        <button class="btn btn-outline-secondary" type="button" onclick="setAllRecipients('{{ $uid }}', true)">All</button>
        <button class="btn btn-outline-secondary" type="button" onclick="setAllRecipients('{{ $uid }}', false)">None</button>
    </div>

    <div id="recipient_list_{{ $uid }}" class="border rounded p-2" style="max-height: 240px; overflow-y: auto;">
        @forelse($users as $u)
        <div class="form-check recipient-option" data-search="{{ strtolower($u->name . ' ' . $u->email) }}">
            <input class="form-check-input" type="checkbox" name="recipient_user_ids[]"
                   value="{{ $u->id }}" id="recipient_{{ $uid }}_{{ $u->id }}"
                   {{ in_array((int) $u->id, $selectedIds, true) ? 'checked' : '' }}>
            <label class="form-check-label d-flex justify-content-between w-100" for="recipient_{{ $uid }}_{{ $u->id }}">
                <span>{{ $u->name }} <span class="text-muted small">({{ $u->email }})</span></span>
                @if(empty($u->whatsapp_number))
                <span class="text-muted small" title="No WhatsApp number on this user's profile — WhatsApp sends skip them">
                    <i class="bi bi-whatsapp"></i> —
                </span>
                @else
                <span class="text-success small" title="WhatsApp: {{ $u->whatsapp_number }}">
                    <i class="bi bi-whatsapp"></i>
                </span>
                @endif
            </label>
        </div>
        @empty
        <div class="text-muted small">No users found.</div>
        @endforelse
    </div>
    <div class="form-text">Everyone ticked gets this notification on the channels enabled below.</div>
</div>
