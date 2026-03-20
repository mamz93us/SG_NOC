@extends('layouts.admin')

@section('title', 'Workflow Builder — ' . $workflowTemplate->display_name)

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css">
<style>
    #drawflow-wrap { position: relative; width: 100%; height: calc(100vh - 130px); overflow: hidden; }
    #drawflow { width: 100%; height: 100%; background: #f8f9fa; }
    .drawflow-node { background: #fff; border: 1.5px solid #dee2e6; border-radius: 10px; padding: 0 !important; min-width: 200px; box-shadow: 0 2px 8px rgba(0,0,0,.07); }
    .drawflow-node.selected { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.2); }
    .drawflow-node .drawflow_content_node { padding: 0 !important; }
    .node-card { padding: 12px 14px; border-radius: 8px; }
    .node-type-approval   { border-left: 4px solid #0d6efd; }
    .node-type-action     { border-left: 4px solid #198754; }
    .node-type-condition  { border-left: 4px solid #ffc107; }
    .node-type-notification { border-left: 4px solid #0dcaf0; }
    .node-type-wait       { border-left: 4px solid #6f42c1; }
    .drag-item { cursor: grab; user-select: none; }
    .drag-item:active { cursor: grabbing; }
    .prop-panel { height: calc(100vh - 130px); overflow-y: auto; }
    .drawflow .connection .main-path { stroke: #0d6efd; stroke-width: 2.5; }
    [data-bs-theme="dark"] #drawflow { background: #1a1d21; }
    [data-bs-theme="dark"] .drawflow-node { background: #2b2d31; border-color: #444; }
    /* Remove Drawflow default node padding that fights our card */
    .drawflow-node > .drawflow_content_node > div { border-radius: 8px; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-3">
        <a href="{{ route('admin.workflow-templates.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div>
            <h5 class="mb-0 fw-bold">{{ $workflowTemplate->display_name }}</h5>
            <small class="text-muted">{{ $workflowTemplate->type_slug }}</small>
        </div>
        <span class="badge bg-secondary" id="version-badge">v{{ $workflowTemplate->version }}</span>
        @if($workflowTemplate->trigger_event)
        <span class="badge bg-success" id="trigger-badge">
            <i class="bi bi-lightning-fill"></i> {{ $workflowTemplate->trigger_event }}
        </span>
        @else
        <span class="badge d-none" id="trigger-badge"></span>
        @endif
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#historyCanvas">
            <i class="bi bi-clock-history me-1"></i>Version History
        </button>
        <button class="btn btn-sm btn-primary" id="btn-save" onclick="saveDefinition()">
            <i class="bi bi-floppy me-1"></i>Save
        </button>
    </div>
</div>

<div class="row g-0" style="height: calc(100vh - 130px);">

    {{-- Left Panel: Step Types + Trigger --}}
    <div class="col-auto" style="width: 210px;">
        <div class="card h-100 border-end rounded-0 border-0 border-end">
            <div class="card-body p-3">
                <p class="text-uppercase text-muted fw-bold small mb-2">Step Types</p>

                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="drag-item card border-start border-primary border-3 p-2 shadow-sm"
                         draggable="true" ondragstart="dragStart(event, 'approval')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-check-fill text-primary fs-5"></i>
                            <div><div class="fw-semibold small">Approval</div><div class="text-muted" style="font-size:11px">Human review</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-success border-3 p-2 shadow-sm"
                         draggable="true" ondragstart="dragStart(event, 'action')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-gear-fill text-success fs-5"></i>
                            <div><div class="fw-semibold small">Action</div><div class="text-muted" style="font-size:11px">Run a job</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-warning border-3 p-2 shadow-sm"
                         draggable="true" ondragstart="dragStart(event, 'condition')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-signpost-split-fill text-warning fs-5"></i>
                            <div><div class="fw-semibold small">Condition</div><div class="text-muted" style="font-size:11px">Branch on value</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-info border-3 p-2 shadow-sm"
                         draggable="true" ondragstart="dragStart(event, 'notification')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-bell-fill text-info fs-5"></i>
                            <div><div class="fw-semibold small">Notification</div><div class="text-muted" style="font-size:11px">Email / Webhook</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-3 p-2 shadow-sm"
                         style="border-color:#6f42c1 !important" draggable="true"
                         ondragstart="dragStart(event, 'wait')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-hourglass-split fs-5" style="color:#6f42c1"></i>
                            <div><div class="fw-semibold small">Wait</div><div class="text-muted" style="font-size:11px">Pause X hours</div></div>
                        </div>
                    </div>
                </div>

                <hr>
                <p class="text-uppercase text-muted fw-bold small mb-2">Event Trigger</p>
                <select class="form-select form-select-sm" id="trigger-select">
                    <option value="">— None —</option>
                    @foreach($triggerEvents as $key => $label)
                    <option value="{{ $key }}" @selected($workflowTemplate->trigger_event === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-outline-secondary w-100 mt-2" onclick="saveTrigger()">
                    <i class="bi bi-save me-1"></i>Set Trigger
                </button>
            </div>
        </div>
    </div>

    {{-- Center: Drawflow Canvas --}}
    <div class="col" id="drawflow-wrap">
        <div id="drawflow"
             ondragover="event.preventDefault()"
             ondrop="dropNode(event)"></div>
    </div>

    {{-- Right: Properties Panel --}}
    <div class="col-auto prop-panel p-0" style="width: 280px;" x-data="propPanel()">
        <div class="card h-100 border-start rounded-0 border-0 border-start">
            <div class="card-body p-3">
                <p class="text-uppercase text-muted fw-bold small mb-3">Properties</p>

                <template x-if="!selectedId">
                    <p class="text-muted small">Select a node on the canvas to edit its properties.</p>
                </template>

                <template x-if="selectedId">
                    <div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Label</label>
                            <input type="text" class="form-control form-control-sm" x-model="data.label"
                                   @input.debounce.400ms="sync()">
                        </div>

                        {{-- Approval fields --}}
                        <template x-if="type === 'approval'">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Approver Role</label>
                                <select class="form-select form-select-sm" x-model="data.role" @change="sync()">
                                    <option value="it_manager">IT Manager</option>
                                    <option value="hr">HR</option>
                                    <option value="manager">Manager</option>
                                    <option value="security">Security</option>
                                    <option value="super_admin">Super Admin</option>
                                </select>
                            </div>
                        </template>

                        {{-- Action fields — dynamic form per job --}}
                        <template x-if="type === 'action'">
                            <div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Job to Run</label>
                                    <select class="form-select form-select-sm" x-model="data.job_class" @change="onJobChange()">
                                        <option value="">— Select a job —</option>
                                        @foreach($jobRegistry as $group => $jobs)
                                        <optgroup label="{{ $group }}">
                                            @foreach($jobs as $job)
                                            <option value="{{ $job['class'] }}">{{ $job['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Dynamic parameter form --}}
                                <template x-if="currentJobParams.length > 0">
                                    <div>
                                        <p class="small fw-semibold text-muted text-uppercase mb-2" style="font-size:10px; letter-spacing:.05em">Parameters</p>
                                        <template x-for="param in currentJobParams" :key="param.key">
                                            <div class="mb-2">
                                                <label class="form-label small mb-1" x-text="param.label"></label>
                                                <template x-if="param.type === 'select'">
                                                    <select class="form-select form-select-sm"
                                                            @change="setParam(param.key, $event.target.value)">
                                                        <template x-for="opt in param.options" :key="opt.value">
                                                            <option :value="opt.value"
                                                                    :selected="(data.params?.[param.key] ?? '') === opt.value"
                                                                    x-text="opt.label"></option>
                                                        </template>
                                                    </select>
                                                </template>
                                                <template x-if="param.type === 'textarea'">
                                                    <textarea class="form-control form-control-sm font-monospace" rows="3"
                                                              :placeholder="param.placeholder || ''"
                                                              @input.debounce.400ms="setParam(param.key, $event.target.value)"
                                                              x-text="data.params?.[param.key] ?? ''"></textarea>
                                                </template>
                                                <template x-if="!param.type || param.type === 'text'">
                                                    <input type="text" class="form-control form-control-sm"
                                                           :placeholder="param.placeholder || ('@{{payload.' + param.key + '}}')"
                                                           :value="data.params?.[param.key] ?? ''"
                                                           @input.debounce.400ms="setParam(param.key, $event.target.value)">
                                                </template>
                                                <div class="form-text" x-show="param.hint" x-text="param.hint" style="font-size:10px"></div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template x-if="data.job_class && currentJobParams.length === 0">
                                    <p class="text-muted small">No configurable parameters for this job.</p>
                                </template>
                            </div>
                        </template>

                        {{-- Condition fields --}}
                        <template x-if="type === 'condition'">
                            <div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Field</label>
                                    <input class="form-control form-control-sm" x-model="data.field" @input.debounce.400ms="sync()" placeholder="e.g. department">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Operator</label>
                                    <select class="form-select form-select-sm" x-model="data.operator" @change="sync()">
                                        <option value="equals">equals</option>
                                        <option value="not_equals">not equals</option>
                                        <option value="contains">contains</option>
                                        <option value="gt">greater than</option>
                                        <option value="lt">less than</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Value</label>
                                    <input class="form-control form-control-sm" x-model="data.value" @input.debounce.400ms="sync()" placeholder="e.g. IT">
                                </div>
                                <div class="alert alert-warning p-2 small mt-2">
                                    <strong>Output 1</strong> = true branch<br>
                                    <strong>Output 2</strong> = false branch
                                </div>
                            </div>
                        </template>

                        {{-- Notification fields --}}
                        <template x-if="type === 'notification'">
                            <div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Channel</label>
                                    <select class="form-select form-select-sm" x-model="data.channel" @change="sync()">
                                        <option value="email">Email</option>
                                        <option value="webhook">Webhook</option>
                                        <option value="slack">Slack</option>
                                        <option value="teams">Microsoft Teams</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Recipient / URL</label>
                                    <input class="form-control form-control-sm" x-model="data.recipient" @input.debounce.400ms="sync()"
                                           :placeholder="data.channel === 'webhook' || data.channel === 'slack' || data.channel === 'teams' ? 'https://...' : 'role:it_manager or email'">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Subject</label>
                                    <input class="form-control form-control-sm" x-model="data.subject" @input.debounce.400ms="sync()">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Body</label>
                                    <textarea class="form-control form-control-sm" rows="3" x-model="data.body" @input.debounce.400ms="sync()"></textarea>
                                </div>
                            </div>
                        </template>

                        {{-- Wait fields --}}
                        <template x-if="type === 'wait'">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Wait Duration (hours)</label>
                                <input type="number" class="form-control form-control-sm" x-model.number="data.hours"
                                       @input.debounce.400ms="sync()" min="1" max="720">
                                <div class="form-text">Max 720 hours (30 days)</div>
                            </div>
                        </template>

                        <hr>
                        <button class="btn btn-sm btn-outline-danger w-100" @click="deleteSelected()">
                            <i class="bi bi-trash me-1"></i>Delete Node
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

{{-- Version History Offcanvas --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="historyCanvas" style="width: 360px;">
    <div class="offcanvas-header">
        <h6 class="offcanvas-title fw-bold"><i class="bi bi-clock-history me-2"></i>Version History</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div id="version-list" class="list-group list-group-flush">
            <div class="p-3 text-muted small">Loading...</div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.js"></script>
<script>
// ── Job registry (from PHP) ────────────────────────────────────
// Flat map: class → { label, params[] }
const JOB_REGISTRY = {};
@foreach($jobRegistry as $group => $jobs)
@foreach($jobs as $job)
JOB_REGISTRY[{{ json_encode($job['class']) }}] = {
    label:  {{ json_encode($job['label']) }},
    params: @json($job['params'] ?? []),
};
@endforeach
@endforeach

// ── Drawflow init ──────────────────────────────────────────────
const dfEl   = document.getElementById('drawflow');
const editor = new Drawflow(dfEl);
editor.reroute = true;
editor.start();

// ── Node HTML templates ────────────────────────────────────────
const nodeTemplates = {
    approval: (d) => `
        <div class="node-card node-type-approval">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-person-check-fill text-primary"></i>
                <strong class="small">Approval</strong>
            </div>
            <div class="text-muted small">${escHtml(d.label || 'Approval Step')}</div>
            <div class="badge bg-primary-subtle text-primary mt-1">${escHtml(d.role || 'it_manager')}</div>
        </div>`,

    action: (d) => `
        <div class="node-card node-type-action">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-gear-fill text-success"></i>
                <strong class="small">Action</strong>
            </div>
            <div class="text-muted small">${escHtml(d.label || 'Run Job')}</div>
            ${d.job_class && JOB_REGISTRY[d.job_class]
                ? `<div class="badge bg-success-subtle text-success mt-1" style="font-size:10px;white-space:normal">${escHtml(JOB_REGISTRY[d.job_class].label)}</div>`
                : '<div class="badge bg-secondary-subtle text-secondary mt-1" style="font-size:10px">No job selected</div>'}
        </div>`,

    condition: (d) => `
        <div class="node-card node-type-condition">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-signpost-split-fill text-warning"></i>
                <strong class="small">Condition</strong>
            </div>
            <div class="text-muted small">${escHtml(d.field || 'field')} ${escHtml(d.operator || 'equals')} <em>${escHtml(d.value || '?')}</em></div>
            <div class="d-flex gap-2 mt-1">
                <span class="badge bg-success-subtle text-success" style="font-size:10px">True →</span>
                <span class="badge bg-danger-subtle text-danger" style="font-size:10px">False →</span>
            </div>
        </div>`,

    notification: (d) => `
        <div class="node-card node-type-notification">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-bell-fill text-info"></i>
                <strong class="small">Notification</strong>
            </div>
            <div class="text-muted small">${escHtml(d.label || d.channel || 'Notify')}</div>
            ${d.channel ? `<div class="badge bg-info-subtle text-info mt-1" style="font-size:10px">${escHtml(d.channel)}</div>` : ''}
        </div>`,

    wait: (d) => `
        <div class="node-card node-type-wait">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-hourglass-split" style="color:#6f42c1"></i>
                <strong class="small">Wait</strong>
            </div>
            <div class="text-muted small">${escHtml(String(d.hours || 24))} hour(s)</div>
        </div>`,
};

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Node output/input config
const nodeOutputs  = { approval: 1, action: 1, condition: 2, notification: 1, wait: 1 };
const nodeInputs   = { approval: 1, action: 1, condition: 1, notification: 1, wait: 1 };
const nodeDefaults = {
    approval:     { label: 'Approval', role: 'it_manager' },
    action:       { label: 'Run Job', job_class: '', params: {} },
    condition:    { label: 'Condition', field: '', operator: 'equals', value: '' },
    notification: { label: 'Notification', channel: 'email', recipient: '', subject: '', body: '' },
    wait:         { label: 'Wait', hours: 24 },
};

// ── Drag & Drop ─────────────────────────────────────────────────
let _dragType = null;
function dragStart(e, type) {
    _dragType = type;
    e.dataTransfer.setData('text/plain', type);
    e.dataTransfer.effectAllowed = 'copy';
}

function dropNode(e) {
    e.preventDefault();
    const type = _dragType || e.dataTransfer.getData('text/plain');
    _dragType = null;
    if (!type || !nodeTemplates[type]) return;

    // Use precanvas bounding rect — this correctly accounts for pan+zoom
    const preRect = editor.precanvas.getBoundingClientRect();
    const zoom    = editor.zoom || 1;
    const pos_x   = (e.clientX - preRect.x) / zoom;
    const pos_y   = (e.clientY - preRect.y) / zoom;

    const data = { ...nodeDefaults[type] };
    editor.addNode(
        type,
        nodeInputs[type],
        nodeOutputs[type],
        pos_x, pos_y,
        type,
        data,
        nodeTemplates[type](data)
    );
}

// ── Load existing definition ──────────────────────────────────
@if($workflowTemplate->definition)
try { editor.import(@json($workflowTemplate->definition)); } catch(e) { console.warn('Could not import definition:', e); }
@endif

// ── Save definition ───────────────────────────────────────────
async function saveDefinition() {
    const btn = document.getElementById('btn-save');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const definition = editor.export();
        const resp = await fetch('{{ route('admin.workflow-templates.save-definition', $workflowTemplate) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ definition, trigger_event: document.getElementById('trigger-select').value || null }),
        });
        const json = await resp.json();
        if (json.ok) {
            document.getElementById('version-badge').textContent = 'v' + json.version;
            showToast('Saved successfully', 'success');
        } else {
            showToast('Save failed: ' + (json.error || 'unknown error'), 'danger');
        }
    } catch(e) {
        showToast('Save failed: ' + e.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save';
    }
}

// ── Set trigger ───────────────────────────────────────────────
async function saveTrigger() {
    const val = document.getElementById('trigger-select').value;
    const url = val
        ? '{{ route('admin.workflow-templates.trigger.set', $workflowTemplate) }}'
        : '{{ route('admin.workflow-templates.trigger.clear', $workflowTemplate) }}';

    const resp = await fetch(url, {
        method: val ? 'POST' : 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: val ? JSON.stringify({ trigger_event: val }) : null,
    });
    const json = await resp.json();
    if (json.ok) {
        const badge = document.getElementById('trigger-badge');
        if (val) {
            badge.className = 'badge bg-success';
            badge.innerHTML = '<i class="bi bi-lightning-fill"></i> ' + val;
        } else {
            badge.className = 'badge d-none';
        }
        showToast('Trigger ' + (val ? 'set to ' + val : 'cleared'), 'success');
    }
}

// ── Version history ───────────────────────────────────────────
document.getElementById('historyCanvas').addEventListener('show.bs.offcanvas', loadVersions);

async function loadVersions() {
    const list = document.getElementById('version-list');
    list.innerHTML = '<div class="p-3 text-muted small">Loading...</div>';
    try {
        const resp = await fetch('{{ route('admin.workflow-templates.versions', $workflowTemplate) }}');
        const versions = await resp.json();
        if (!versions.length) { list.innerHTML = '<div class="p-3 text-muted small">No previous versions.</div>'; return; }
        list.innerHTML = versions.map(v => `
            <div class="list-group-item list-group-item-action px-3 py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>v${v.version}</strong>
                        <div class="text-muted small">${v.editor ? escHtml(v.editor.name) : 'System'} &middot; ${new Date(v.created_at).toLocaleString()}</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary py-0" onclick="restoreVersion(${v.version})">Restore</button>
                </div>
            </div>`).join('');
    } catch(e) { list.innerHTML = '<div class="p-3 text-danger small">Failed to load versions.</div>'; }
}

async function restoreVersion(version) {
    if (!confirm(`Restore v${version}? Current state will be saved first.`)) return;
    const resp = await fetch(
        '{{ route('admin.workflow-templates.restore-version', [$workflowTemplate, '__v__']) }}'.replace('__v__', version),
        { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } }
    );
    const json = await resp.json();
    if (json.ok) {
        document.getElementById('version-badge').textContent = 'v' + json.version;
        if (json.definition) { editor.import(json.definition); }
        showToast(`Restored to v${version}`, 'info');
        bootstrap.Offcanvas.getInstance(document.getElementById('historyCanvas'))?.hide();
    }
}

// ── Properties Panel (Alpine component) ───────────────────────
function propPanel() {
    return {
        selectedId: null,
        type: null,
        data: {},

        init() {
            editor.on('nodeSelected', (id) => {
                this.selectedId = id;
                const node = editor.getNodeFromId(id);
                this.type = node.name;
                // Merge defaults so all keys exist, then overlay saved data
                this.data = { ...nodeDefaults[this.type], ...node.data };
                if (this.type === 'action' && !this.data.params) {
                    this.data.params = {};
                }
            });
            editor.on('nodeUnselected', () => {
                this.selectedId = null;
                this.type = null;
                this.data = {};
            });
        },

        // Returns the param schema array for the currently selected job
        get currentJobParams() {
            if (this.type !== 'action' || !this.data.job_class) return [];
            const job = JOB_REGISTRY[this.data.job_class];
            if (!job) return [];
            // params can be array of strings OR array of param objects
            return (job.params || []).map(p => {
                if (typeof p === 'string') {
                    return {
                        key: p,
                        label: p.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                        type: 'text',
                        placeholder: null,
                        hint: null,
                        options: [],
                    };
                }
                return { type: 'text', placeholder: null, hint: null, options: [], ...p };
            });
        },

        onJobChange() {
            if (!this.data.params) this.data.params = {};
            this.sync();
        },

        setParam(key, value) {
            if (!this.data.params) this.data.params = {};
            this.data.params = { ...this.data.params, [key]: value };
            this.sync();
        },

        sync() {
            if (!this.selectedId) return;
            editor.updateNodeDataFromId(this.selectedId, { ...this.data });
            // Refresh node card HTML in canvas
            const contentEl = document.querySelector(`#node-${this.selectedId} .drawflow_content_node`);
            if (contentEl && nodeTemplates[this.type]) {
                contentEl.innerHTML = nodeTemplates[this.type](this.data);
            }
        },

        deleteSelected() {
            if (this.selectedId && confirm('Delete this node?')) {
                editor.removeNodeId('node-' + this.selectedId);
                this.selectedId = null;
                this.type = null;
                this.data = {};
            }
        },
    };
}

// ── Toast helper ──────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3`;
    t.style.zIndex = 9999;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${escHtml(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}
</script>
@endpush
