@extends('layouts.admin')

@section('title', 'Workflow Builder — ' . $workflowTemplate->display_name)

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css">
<style>
    #drawflow-container { position: relative; width: 100%; height: calc(100vh - 130px); }
    #drawflow { width: 100%; height: 100%; background: #f8f9fa; }
    .drawflow-node { background: #fff; border: 1.5px solid #dee2e6; border-radius: 10px; padding: 0; min-width: 200px; box-shadow: 0 2px 8px rgba(0,0,0,.07); }
    .drawflow-node.selected { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.2); }
    .drawflow-node .drawflow_content_node { padding: 0; }
    .node-card { padding: 12px 14px; }
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
        <span class="badge bg-outline-secondary d-none" id="trigger-badge"></span>
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
                         draggable="true" data-node="approval"
                         ondragstart="dragStart(event, 'approval')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-check-fill text-primary fs-5"></i>
                            <div><div class="fw-semibold small">Approval</div><div class="text-muted" style="font-size:11px">Human review</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-success border-3 p-2 shadow-sm"
                         draggable="true" data-node="action"
                         ondragstart="dragStart(event, 'action')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-gear-fill text-success fs-5"></i>
                            <div><div class="fw-semibold small">Action</div><div class="text-muted" style="font-size:11px">Run a job</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-warning border-3 p-2 shadow-sm"
                         draggable="true" data-node="condition"
                         ondragstart="dragStart(event, 'condition')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-signpost-split-fill text-warning fs-5"></i>
                            <div><div class="fw-semibold small">Condition</div><div class="text-muted" style="font-size:11px">Branch on value</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-info border-3 p-2 shadow-sm"
                         draggable="true" data-node="notification"
                         ondragstart="dragStart(event, 'notification')">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-bell-fill text-info fs-5"></i>
                            <div><div class="fw-semibold small">Notification</div><div class="text-muted" style="font-size:11px">Email / Webhook</div></div>
                        </div>
                    </div>

                    <div class="drag-item card border-start border-3 p-2 shadow-sm"
                         style="border-color:#6f42c1 !important" draggable="true" data-node="wait"
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
    <div class="col" id="drawflow-container"
         ondragover="event.preventDefault()"
         ondrop="dropNode(event)">
        <div id="drawflow"></div>
    </div>

    {{-- Right: Properties Panel --}}
    <div class="col-auto prop-panel p-0" style="width: 260px;" x-data="propPanel()">
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

                        {{-- Action fields --}}
                        <template x-if="type === 'action'">
                            <div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Job to Run</label>
                                    <select class="form-select form-select-sm" x-model="data.job_class" @change="sync()">
                                        <option value="">— Select —</option>
                                        @foreach($jobRegistry as $job)
                                        <option value="{{ $job['class'] }}">{{ $job['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Params (JSON)</label>
                                    <textarea class="form-control form-control-sm font-monospace" rows="3"
                                              x-model="data.params_json" @input.debounce.600ms="syncParams()"></textarea>
                                    <div class="form-text">Use <code>{{payload.field}}</code> for workflow data</div>
                                </div>
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
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Recipient / URL</label>
                                    <input class="form-control form-control-sm" x-model="data.recipient" @input.debounce.400ms="sync()"
                                           :placeholder="data.channel === 'webhook' ? 'https://...' : 'role:it_manager or email'">
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
// ── Drawflow init ──────────────────────────────────────────────
const el       = document.getElementById('drawflow');
const editor   = new Drawflow(el);
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
            <div class="text-muted small">${d.label || 'Approval Step'}</div>
            <div class="badge bg-primary-subtle text-primary mt-1 small">${d.role || 'it_manager'}</div>
        </div>`,

    action: (d) => `
        <div class="node-card node-type-action">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-gear-fill text-success"></i>
                <strong class="small">Action</strong>
            </div>
            <div class="text-muted small">${d.label || 'Run Job'}</div>
        </div>`,

    condition: (d) => `
        <div class="node-card node-type-condition">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-signpost-split-fill text-warning"></i>
                <strong class="small">Condition</strong>
            </div>
            <div class="text-muted small">${d.field || 'field'} ${d.operator || 'equals'} ${d.value || '?'}</div>
            <div class="d-flex gap-2 mt-1">
                <span class="badge bg-success-subtle text-success small">True →</span>
                <span class="badge bg-danger-subtle text-danger small">False →</span>
            </div>
        </div>`,

    notification: (d) => `
        <div class="node-card node-type-notification">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-bell-fill text-info"></i>
                <strong class="small">Notification</strong>
            </div>
            <div class="text-muted small">${d.label || d.channel || 'Notify'}</div>
        </div>`,

    wait: (d) => `
        <div class="node-card node-type-wait">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-hourglass-split" style="color:#6f42c1"></i>
                <strong class="small">Wait</strong>
            </div>
            <div class="text-muted small">${d.hours || 1} hour(s)</div>
        </div>`,
};

// Node output config per type (condition gets 2 outputs for true/false)
const nodeOutputs = { approval: 1, action: 1, condition: 2, notification: 1, wait: 1 };
const nodeInputs  = { approval: 1, action: 1, condition: 1, notification: 1, wait: 1 };
const nodeDefaults = {
    approval:     { label: 'Approval', role: 'it_manager' },
    action:       { label: 'Run Job', job_class: '', params: {}, params_json: '{}' },
    condition:    { label: 'Condition', field: '', operator: 'equals', value: '' },
    notification: { label: 'Notification', channel: 'email', recipient: '', subject: '', body: '' },
    wait:         { label: 'Wait', hours: 24 },
};

// ── Drag & Drop ────────────────────────────────────────────────
let dragType = null;
function dragStart(e, type) { dragType = type; e.dataTransfer.setData('text', type); }

function dropNode(e) {
    e.preventDefault();
    const type = dragType || e.dataTransfer.getData('text');
    if (!type || !nodeTemplates[type]) return;

    const rect = el.getBoundingClientRect();
    const x = e.clientX - rect.left - editor.canvas_x;
    const y = e.clientY - rect.top  - editor.canvas_y;

    const data = { ...nodeDefaults[type] };
    editor.addNode(type, nodeInputs[type], nodeOutputs[type], x / editor.zoom, y / editor.zoom, type, data, nodeTemplates[type](data));
    dragType = null;
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
        showToast('Trigger ' + (val ? 'set' : 'cleared'), 'success');
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
                        <div class="text-muted small">${v.editor ? v.editor.name : 'System'} · ${new Date(v.created_at).toLocaleString()}</div>
                    </div>
                    <button class="btn btn-xs btn-outline-secondary" onclick="restoreVersion(${v.version})">Restore</button>
                </div>
            </div>`).join('');
    } catch(e) { list.innerHTML = '<div class="p-3 text-danger small">Failed to load versions.</div>'; }
}

async function restoreVersion(version) {
    if (!confirm(`Restore v${version}? Current state will be saved first.`)) return;
    const resp = await fetch(`{{ route('admin.workflow-templates.restore-version', [$workflowTemplate, '__v__']) }}`.replace('__v__', version), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    });
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
                this.data = { ...nodeDefaults[this.type], ...node.data };
                if (this.type === 'action' && typeof this.data.params === 'object') {
                    this.data.params_json = JSON.stringify(this.data.params, null, 2);
                }
            });
            editor.on('nodeUnselected', () => { this.selectedId = null; this.type = null; });
        },

        sync() {
            if (!this.selectedId) return;
            editor.updateNodeDataFromId(this.selectedId, { ...this.data });
            // Refresh node HTML
            const node = editor.getNodeFromId(this.selectedId);
            const html = nodeTemplates[this.type]?.(node.data) ?? '';
            document.querySelector(`#node-${this.selectedId} .drawflow_content_node`).innerHTML = html;
        },

        syncParams() {
            try {
                this.data.params = JSON.parse(this.data.params_json);
                this.sync();
            } catch { /* ignore parse error while typing */ }
        },

        deleteSelected() {
            if (this.selectedId && confirm('Delete this node?')) {
                editor.removeNodeId('node-' + this.selectedId);
                this.selectedId = null;
            }
        },
    };
}

// ── Toast helper ──────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3`;
    t.style.zIndex = 9999;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}
</script>
@endpush
