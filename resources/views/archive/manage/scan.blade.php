@extends('layouts.archive')

@section('title', 'Scan destinations')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('archive.manage.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Manage
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">Scan destinations</span>
    </div>

    {{-- ── The once-only reveals ───────────────────────────────────── --}}
    @if ($newToken)
        <div class="arc-card p-3 mb-3" style="border-color: var(--green)">
            <h2 class="h6 mb-2"><i class="bi bi-check2-circle" style="color:var(--green)"></i> Put this address into the copier</h2>
            <code class="d-block p-2 mb-2" style="background:var(--bg);border-radius:6px;font-size:1rem">{{ $newToken }}</code>
            <p class="arc-muted small mb-0">
                Shown only now — it is stored hashed and cannot be read back. Lost it? Use
                <strong>New address</strong> on the row, which replaces it.
            </p>
        </div>
    @endif

    @if ($newPassword)
        <div class="arc-card p-3 mb-3" style="border-color: var(--green)">
            <h2 class="h6 mb-2"><i class="bi bi-check2-circle" style="color:var(--green)"></i> Put these into the copier</h2>
            <dl class="row small mb-2">
                <dt class="col-sm-3 arc-muted fw-normal">Protocol</dt>
                <dd class="col-sm-9">SFTP (port 2022) or FTPS (2121)</dd>
                <dt class="col-sm-3 arc-muted fw-normal">User</dt>
                <dd class="col-sm-9"><code>{{ $newPassword['username'] }}</code></dd>
                <dt class="col-sm-3 arc-muted fw-normal">Password</dt>
                <dd class="col-sm-9"><code>{{ $newPassword['password'] }}</code></dd>
                <dt class="col-sm-3 arc-muted fw-normal">Folder</dt>
                <dd class="col-sm-9"><code>/</code> <span class="arc-muted">({{ $newPassword['folder'] }} on the server)</span></dd>
            </dl>
            <p class="arc-muted small mb-0">The password is shown only now.</p>
        </div>
    @endif

    {{-- ── Whether the server side is actually ready ───────────────── --}}
    @unless ($mailReady && $sftpgoReady)
        <div class="arc-card p-3 mb-3">
            <h2 class="h6 mb-2">Server setup</h2>
            <ul class="list-unstyled small mb-0">
                <li class="mb-1">
                    @if ($mailReady)
                        <i class="bi bi-check-circle" style="color:var(--green)"></i>
                        Mail spool ready at <code>{{ $spool }}</code>
                    @else
                        <i class="bi bi-exclamation-triangle" style="color:var(--amber)"></i>
                        No mail spool at <code>{{ $spool }}</code> — run
                        <code>deployment/archive-portal/scan-mail.sh</code> on the NOC.
                        Addresses can be created now; nothing will arrive until then.
                    @endif
                </li>
                <li>
                    @if ($sftpgoReady)
                        <i class="bi bi-check-circle" style="color:var(--green)"></i>
                        SFTPGo configured
                    @else
                        <i class="bi bi-exclamation-triangle" style="color:var(--amber)"></i>
                        SFTPGo is not configured, so folder destinations cannot be created.
                        Set it up under the NOC's Settings ▸ SFTPGo.
                    @endif
                </li>
            </ul>
        </div>
    @endunless

    <div class="row g-3">
        {{-- ── Add one ─────────────────────────────────────────────── --}}
        <div class="col-lg-4">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Add a destination</h2>

                <form method="POST" action="{{ route('archive.manage.scan.store') }}">
                    @csrf

                    <label class="form-label small arc-muted mb-1">How the copier sends</label>
                    <select class="form-select form-select-sm mb-2" name="type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @disabled($value === 'folder' && ! $sftpgoReady)>
                                {{ $label }}@if ($value === 'folder' && ! $sftpgoReady) — SFTPGo not configured @endif
                            </option>
                        @endforeach
                    </select>

                    <label class="form-label small arc-muted mb-1">What it is</label>
                    <input class="form-control form-control-sm mb-2" name="label" maxlength="120" required
                           value="{{ old('label') }}" placeholder="Reception Ricoh, 2nd floor">

                    <label class="form-label small arc-muted mb-1">Scans go to</label>
                    <select class="form-select form-select-sm" name="goes_to" required>
                        <option value="">Choose…</option>
                        <option value="user:{{ auth()->id() }}">My own inbox</option>
                        @foreach ($archives as $archive)
                            <option value="archive:{{ $archive->id }}">
                                {{ $archive->displayName() }} — shared inbox
                            </option>
                        @endforeach
                    </select>

                    <div class="form-text small">
                        A shared archive inbox is seen by everybody who may add documents to
                        that archive. Only archives this portal owns can receive scans.
                    </div>

                    <button class="btn btn-brand btn-sm w-100 mt-2">Create</button>
                </form>
            </div>

            <div class="arc-card p-3">
                <h2 class="h6 mb-2">What a destination can do</h2>
                <p class="arc-muted small mb-0">
                    Nothing but <strong>put documents in</strong>. A scan address or folder login
                    cannot read, search or download anything — filing what arrives is still a
                    signed-in action. That is why an address a copier holds in plain settings is
                    safe to hand out, and why it must never be extended to hand anything back.
                </p>
            </div>
        </div>

        {{-- ── The destinations ────────────────────────────────────── --}}
        <div class="col-lg-8">
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Destinations</h2>

                @if ($endpoints->isEmpty())
                    <div class="arc-empty">
                        <i class="bi bi-printer" style="font-size:1.5rem"></i>
                        <p class="mt-2 mb-0">None yet.</p>
                        <p class="small mb-0">
                            Add one, then put its address or login into the copier once.
                        </p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Destination</th>
                                    <th>Address / login</th>
                                    <th>Goes to</th>
                                    <th>Last scan</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($endpoints as $endpoint)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $endpoint->label }}</div>
                                            <div class="arc-muted small">
                                                {{ $types[$endpoint->type] ?? $endpoint->type }}
                                                @unless ($endpoint->enabled)
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">off</span>
                                                @endunless
                                            </div>
                                        </td>

                                        <td class="small text-break">
                                            @if ($endpoint->isEmail())
                                                <code>{{ $endpoint->maskedAddress() }}</code>
                                            @else
                                                <code>{{ $endpoint->sftpgo_username }}</code>
                                            @endif
                                        </td>

                                        <td class="small">{{ $endpoint->destinationLabel() }}</td>

                                        <td class="small">
                                            @if ($endpoint->last_received_at)
                                                {{ $endpoint->last_received_at->diffForHumans() }}
                                                <div class="arc-muted small">{{ number_format($endpoint->received_count) }} in total</div>
                                            @else
                                                {{-- A scanner that has never sent anything is usually a
                                                     copier configured wrongly, and worth showing plainly. --}}
                                                <span style="color:var(--amber)">nothing yet</span>
                                            @endif

                                            @if ($endpoint->last_error)
                                                <div class="small text-break" style="color:var(--amber)">{{ $endpoint->last_error }}</div>
                                            @endif
                                        </td>

                                        <td class="text-end text-nowrap">
                                            <form method="POST" action="{{ route('archive.manage.scan.rotate', $endpoint) }}"
                                                  class="d-inline m-0"
                                                  onsubmit="return confirm('Replace it? The copier stops working until you update its settings.')">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">
                                                    {{ $endpoint->isEmail() ? 'New address' : 'New password' }}
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('archive.manage.scan.toggle', $endpoint) }}"
                                                  class="d-inline m-0">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">
                                                    {{ $endpoint->enabled ? 'Switch off' : 'Switch on' }}
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('archive.manage.scan.destroy', $endpoint) }}"
                                                  class="d-inline m-0"
                                                  onsubmit="return confirm('Delete {{ $endpoint->label }}? Scans already received are kept.')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-secondary">Delete</button>
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
