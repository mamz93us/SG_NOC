@extends('layouts.archive')

@section('title', 'AI')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('archive.manage.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Manage
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">AI</span>

        @if ($pendingProposals > 0)
            <a href="{{ route('archive.review') }}" class="ms-auto btn btn-sm btn-brand">
                Review {{ number_format($pendingProposals) }} proposal(s)
            </a>
        @endif
    </div>

    {{-- ── Money ───────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Budget this month</div>
                <div class="h4 mb-0">${{ number_format($settings->budget(), 2) }}</div>
                <div class="arc-muted small">
                    @if ($settings->budget() <= 0)
                        AI is off until this is set
                    @else
                        keyed in, not fetched
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Spent</div>
                <div class="h4 mb-0">${{ number_format($spentThisMonth, 2) }}</div>
                <div class="arc-muted small">measured per call</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Left</div>
                <div class="h4 mb-0" style="color:{{ $remaining > 0 ? 'var(--green)' : 'var(--amber)' }}">
                    ${{ number_format($remaining, 2) }}
                </div>
                <div class="arc-muted small">
                    about {{ number_format($settings->pageCost() > 0 ? (int) ($remaining / $settings->pageCost()) : 0) }} pages
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="arc-card p-3">
                <div class="arc-muted small">Waiting for review</div>
                <div class="h4 mb-0">{{ number_format($pendingProposals) }}</div>
                <div class="arc-muted small">proposals, not values</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            {{-- ── Settings ────────────────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">What AI may spend</h2>

                <form method="POST" action="{{ route('archive.manage.ai.settings') }}">
                    @csrf
                    <label class="form-label small arc-muted mb-1">Budget a month (USD)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm mb-2"
                           name="monthly_budget_usd" value="{{ $settings->monthly_budget_usd }}">

                    <label class="form-label small arc-muted mb-1">Pages one person may have read a day</label>
                    <input type="number" min="0" class="form-control form-control-sm mb-2"
                           name="per_user_daily_pages" value="{{ $settings->per_user_daily_pages }}">

                    <label class="form-label small arc-muted mb-1">Cost of reading one page (USD)</label>
                    <input type="number" step="0.00001" min="0" class="form-control form-control-sm mb-2"
                           name="page_read_cost_usd" value="{{ $settings->page_read_cost_usd }}">

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">Per 1k tokens in</label>
                            <input type="number" step="0.00001" min="0" class="form-control form-control-sm"
                                   name="prompt_token_cost_usd" value="{{ $settings->prompt_token_cost_usd }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small arc-muted mb-1">Per 1k tokens out</label>
                            <input type="number" step="0.00001" min="0" class="form-control form-control-sm"
                                   name="completion_token_cost_usd" value="{{ $settings->completion_token_cost_usd }}">
                        </div>
                    </div>

                    <button class="btn btn-brand btn-sm w-100 mt-3">Save</button>
                </form>

                <p class="arc-muted small mb-0 mt-2">
                    A budget of 0 means AI is switched off, not unlimited — the safer reading of
                    “nobody has set this yet”. Batches stop themselves at the budget, and so do
                    questions.
                </p>

                {{-- Said plainly, because a figure that looks like a bill and is not
                     one is how a budget gets trusted past the point it should be. --}}
                <p class="arc-muted small mb-0 mt-2">
                    <i class="bi bi-info-circle"></i>
                    Everything on this page is an <strong>estimate</strong> from the prices above,
                    not Azure's invoice. Reading is counted per page; questions are counted from
                    the tokens each call reported. Correct these prices against a real bill.
                </p>
            </div>

            {{-- ── Where the money went ────────────────────────────── --}}
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">This month, by feature</h2>

                @if (empty($usageByFeature))
                    <p class="arc-muted small mb-0">Nothing spent yet.</p>
                @else
                    <table class="table arc-table mb-0">
                        <tbody>
                            @foreach ($usageByFeature as $feature => $row)
                                <tr>
                                    <td class="small text-capitalize">{{ str_replace('_', ' ', $feature) }}</td>
                                    <td class="arc-muted small text-end">
                                        {{ number_format($row['pages']) }} pages
                                    </td>
                                    <td class="small text-end">${{ number_format($row['cost'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="col-lg-8">
            {{-- ── Start a batch ───────────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Read pages, or fill in what is missing</h2>

                <form method="POST" action="{{ route('archive.manage.ai.start') }}" id="batchForm">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small arc-muted mb-1">Archive</label>
                            <select class="form-select form-select-sm" name="archive_id" id="batchArchive" required>
                                <option value="">Choose…</option>
                                @foreach ($archives as $archive)
                                    <option value="{{ $archive->id }}"
                                            @disabled(! $archive->readable || ! $archive->ai_reading)>
                                        {{ $archive->displayName() }}
                                        @if (! $archive->readable) — encrypted
                                        @elseif (! $archive->ai_reading) — AI reading off
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small arc-muted mb-1">What to do</label>
                            <select class="form-select form-select-sm" name="type" id="batchType">
                                @foreach ($types as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small arc-muted mb-1">Captured from</label>
                            <input type="date" class="form-control form-control-sm" name="from">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small arc-muted mb-1">Until</label>
                            <input type="date" class="form-control form-control-sm" name="to">
                        </div>
                    </div>

                    {{-- Fields, only for a fill batch, and only the chosen archive's own. --}}
                    <div class="mt-3 d-none" id="fieldsBlock">
                        <label class="form-label small arc-muted mb-1">Fields to fill in</label>
                        <div class="border rounded p-2" style="max-height:170px;overflow:auto">
                            @foreach ($archives as $archive)
                                @foreach ($archive->fields as $field)
                                    <div class="form-check form-check-inline d-none arc-field"
                                         data-archive="{{ $archive->id }}">
                                        <input class="form-check-input" type="checkbox" name="field_ids[]"
                                               value="{{ $field->id }}" id="f{{ $field->id }}">
                                        <label class="form-check-label small" for="f{{ $field->id }}">
                                            {{ $field->label() }}
                                        </label>
                                    </div>
                                @endforeach
                            @endforeach
                            <span class="arc-muted small d-none" id="noFields">
                                That archive has no fields recorded.
                            </span>
                        </div>
                        <div class="form-text small">
                            Only documents where the field is empty are read — paying to confirm a
                            value already recorded is money for nothing.
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="estimateBtn">
                            Check the cost
                        </button>
                        <button class="btn btn-sm btn-brand">Start</button>
                        <span class="small arc-muted" id="estimateOut"></span>
                    </div>
                </form>
            </div>

            {{-- ── Per archive ─────────────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">What AI may touch</h2>

                <div class="table-responsive">
                    <table class="table arc-table mb-0">
                        <thead>
                            <tr>
                                <th>Archive</th>
                                <th>Pages read</th>
                                <th>Answer questions</th>
                                <th>May read</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($readProgress as $row)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $row->archive->displayName() }}</div>
                                        @unless ($row->archive->readable)
                                            <span class="badge bg-warning-subtle text-warning-emphasis">encrypted — skipped</span>
                                        @endunless
                                    </td>
                                    <td style="width:22%">
                                        <div class="progress" style="height:8px">
                                            <div class="progress-bar" style="width: {{ $row->percent }}%; background: var(--green)"></div>
                                        </div>
                                        <span class="arc-muted small">
                                            {{ number_format($row->read) }} / {{ number_format($row->total) }} files
                                            @if ($row->failed)
                                                · <span style="color:var(--amber)">{{ number_format($row->failed) }} unreadable</span>
                                            @endif
                                        </span>
                                    </td>
                                    <form method="POST" action="{{ route('archive.manage.ai.archive', $row->archive) }}"
                                          id="a{{ $row->archive->id }}" class="m-0">
                                        @csrf
                                    </form>
                                    <td>
                                        <input class="form-check-input" type="checkbox" name="ai_chat" value="1"
                                               form="a{{ $row->archive->id }}" @checked($row->archive->ai_chat)>
                                    </td>
                                    <td>
                                        <input class="form-check-input" type="checkbox" name="ai_reading" value="1"
                                               form="a{{ $row->archive->id }}" @checked($row->archive->ai_reading)>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary" form="a{{ $row->archive->id }}">
                                            Save
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="arc-muted small mb-0 mt-2">
                    Both are off by default. Leave them off for anything holding HR files.
                </p>
            </div>

            {{-- ── Batches ─────────────────────────────────────────── --}}
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Batches</h2>

                @if ($batches->isEmpty())
                    <p class="arc-muted small mb-0">None started yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead>
                                <tr>
                                    <th>Archive</th><th>What</th><th style="width:22%">Progress</th>
                                    <th>Cost</th><th>State</th><th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($batches as $batch)
                                    <tr>
                                        <td class="small">{{ $batch->archive?->displayName() ?? '—' }}</td>
                                        <td class="arc-muted small">
                                            {{ $batch->type === \App\Models\Archive\ArchiveAiBatch::TYPE_FILL ? 'Fill fields' : 'Read pages' }}
                                        </td>
                                        <td>
                                            <div class="progress" style="height:8px">
                                                <div class="progress-bar" style="width: {{ $batch->percent() }}%; background: var(--green)"></div>
                                            </div>
                                            <span class="arc-muted small">
                                                {{ number_format($batch->documents_done) }} / {{ number_format($batch->documents_total) }}
                                                · {{ number_format($batch->pages_done) }} pages
                                            </span>
                                        </td>
                                        <td class="small">
                                            ${{ number_format((float) $batch->cost_so_far_usd, 2) }}
                                            <span class="arc-muted">of ~${{ number_format((float) $batch->estimated_cost_usd, 2) }}</span>
                                        </td>
                                        <td class="small">
                                            {{ str_replace('_', ' ', $batch->status) }}
                                            @if ($batch->error)
                                                <div class="arc-muted small text-break">{{ $batch->error }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            @if (! $batch->isFinished())
                                                <form method="POST" action="{{ route('archive.manage.ai.batch', $batch) }}" class="d-inline m-0">
                                                    @csrf
                                                    <input type="hidden" name="action"
                                                           value="{{ $batch->status === \App\Models\Archive\ArchiveAiBatch::STATUS_PAUSED ? 'resume' : 'pause' }}">
                                                    <button class="btn btn-sm btn-outline-secondary">
                                                        {{ $batch->status === \App\Models\Archive\ArchiveAiBatch::STATUS_PAUSED ? 'Resume' : 'Pause' }}
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('archive.manage.ai.batch', $batch) }}" class="d-inline m-0">
                                                    @csrf
                                                    <input type="hidden" name="action" value="cancel">
                                                    <button class="btn btn-sm btn-outline-secondary">Cancel</button>
                                                </form>
                                            @elseif ($batch->status === \App\Models\Archive\ArchiveAiBatch::STATUS_OVER_BUDGET)
                                                <form method="POST" action="{{ route('archive.manage.ai.batch', $batch) }}" class="d-inline m-0">
                                                    @csrf
                                                    <input type="hidden" name="action" value="resume">
                                                    <button class="btn btn-sm btn-outline-secondary">Resume</button>
                                                </form>
                                            @endif
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

    {{-- Inline rather than @push('scripts'): a nested view's push can land after
         the code that needs it (see the home portal popup that shipped dead), and
         this script only belongs to this page. --}}
    <script>
    (function () {
        const archive  = document.getElementById('batchArchive');
        const type     = document.getElementById('batchType');
        const block    = document.getElementById('fieldsBlock');
        const none     = document.getElementById('noFields');
        const fields   = Array.from(document.querySelectorAll('.arc-field'));
        const estimate = document.getElementById('estimateBtn');
        const out      = document.getElementById('estimateOut');

        function sync() {
            const filling = type.value === 'fill';
            block.classList.toggle('d-none', !filling);

            let shown = 0;
            fields.forEach(function (row) {
                const mine = row.dataset.archive === archive.value;
                row.classList.toggle('d-none', !mine);
                // Unchecked when hidden, so a field from a previously chosen
                // archive cannot be submitted with this one.
                if (!mine) { row.querySelector('input').checked = false; } else { shown++; }
            });

            none.classList.toggle('d-none', shown > 0);
            out.textContent = '';
        }

        archive.addEventListener('change', sync);
        type.addEventListener('change', sync);
        sync();

        estimate.addEventListener('click', function () {
            if (!archive.value) { out.textContent = 'Choose an archive first.'; return; }

            out.textContent = 'Counting…';

            const form = new FormData(document.getElementById('batchForm'));

            fetch(@json(route('archive.manage.ai.estimate')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: form,
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { out.textContent = d.error; return; }

                out.textContent = Number(d.documents).toLocaleString() + ' document(s), about $'
                    + Number(d.cost).toFixed(2)
                    + (d.known_pages ? '' : ' — page counts are estimated, ArcMate recorded none')
                    + ' · $' + Number(d.budget_remaining).toFixed(2) + ' left this month';
            })
            .catch(function () { out.textContent = 'Could not work that out just now.'; });
        });
    })();
    </script>
@endsection
