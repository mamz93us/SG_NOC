@extends('layouts.admin')

@section('title', 'Sender Addresses')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-envelope-at me-2 text-primary"></i>Sender Addresses</h4>
        <small class="text-muted">Which “From” address each kind of system email goes out as</small>
    </div>
    <a href="{{ route('admin.settings.index') }}#smtp" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear me-1"></i>Mail Settings
    </a>
</div>

<div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle fs-5"></i>
    <div class="small">
        Leave a field <strong>blank to inherit the default</strong>
        (<code>{{ $defaultAddress ?: '— not set —' }}</code>{{ $defaultName ? ' · '.$defaultName : '' }}).
        Currently sending via <strong>{{ strtoupper($transport) }}</strong>.
        @if($transport === 'ses')
            On SES every From address must belong to a verified identity, or the send fails.
        @endif
    </div>
</div>

@if($verifyError)
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Could not load the SES verified-domain list, so verification badges are hidden: {{ $verifyError }}
    </div>
@endif

<form method="POST" action="{{ route('admin.mail-senders.update') }}">
    @csrf
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:230px">Service</th>
                        <th style="min-width:240px">From Address</th>
                        <th style="min-width:180px">From Name</th>
                        <th style="min-width:220px">Reply-To</th>
                        <th style="width:150px" class="text-end">Test</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($senders as $sender)
                    @php
                        $key = $sender->service_key;
                        $domain = $sender->domain();
                        $verified = $domain && in_array($domain, $verifiedDomains, true);
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $sender->label() }}</div>
                            <div class="text-muted small">{{ $sender->description() }}</div>
                        </td>
                        <td>
                            <input type="email" class="form-control form-control-sm font-monospace"
                                   name="senders[{{ $key }}][from_address]"
                                   value="{{ old("senders.$key.from_address", $sender->from_address) }}"
                                   placeholder="{{ $sender->example() }}">
                            @if($transport === 'ses' && $domain && $verifiedDomains)
                                @if($verified)
                                    <span class="badge bg-success-subtle text-success-emphasis mt-1">
                                        <i class="bi bi-check-circle me-1"></i>{{ $domain }} verified
                                    </span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger-emphasis mt-1">
                                        <i class="bi bi-x-circle me-1"></i>{{ $domain }} not verified in SES
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm"
                                   name="senders[{{ $key }}][from_name]"
                                   value="{{ old("senders.$key.from_name", $sender->from_name) }}"
                                   placeholder="{{ $defaultName }}">
                        </td>
                        <td>
                            <input type="email" class="form-control form-control-sm font-monospace"
                                   name="senders[{{ $key }}][reply_to]"
                                   value="{{ old("senders.$key.reply_to", $sender->reply_to) }}"
                                   placeholder="optional">
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#testModal"
                                    data-service="{{ $key }}" data-label="{{ $sender->label() }}">
                                <i class="bi bi-send"></i> Test
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
            <span class="text-muted small">
                Saving takes effect on the next email — no restart needed.
            </span>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Sender Addresses
            </button>
        </div>
    </div>
</form>

{{-- Test-send modal — posts to the per-service test route --}}
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="testForm">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Test sender: <span id="testLabel"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Sends a message using this service’s From address so you can confirm the
                        transport accepts it. Save your changes first — the test uses the
                        <strong>saved</strong> address, not what is typed in the table.
                    </p>
                    <label class="form-label fw-semibold">Send to</label>
                    <input type="email" name="test_email" class="form-control" required
                           value="{{ auth()->user()->email }}">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Send Test
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Point the modal's form at whichever service's Test button was clicked.
document.getElementById('testModal')?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    const service = btn.getAttribute('data-service');
    document.getElementById('testLabel').textContent = btn.getAttribute('data-label');
    document.getElementById('testForm').action =
        @json(route('admin.mail-senders.test', ['serviceKey' => '__KEY__'])).replace('__KEY__', service);
});
</script>
@endpush
@endsection
