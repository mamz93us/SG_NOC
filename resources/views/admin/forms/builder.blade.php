@extends('layouts.admin')
@push('styles')
<style>
.field-palette .field-type-btn { cursor: pointer; user-select: none; }
.field-canvas .canvas-field { background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:10px 12px; margin-bottom:8px; cursor:grab; position:relative; }
.field-canvas .canvas-field:active { cursor:grabbing; }
.field-canvas .canvas-field.sortable-ghost { opacity:.4; background:#e7f1ff; }
.field-canvas .canvas-field .field-remove { position:absolute; top:6px; right:8px; opacity:0; transition:.15s; }
.field-canvas .canvas-field:hover .field-remove { opacity:1; }
.config-panel { min-height:200px; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-ui-checks-grid me-2 text-primary"></i>
            {{ $form ? 'Edit Form: '.$form->name : 'New Form' }}
        </h4>
    </div>
    <a href="{{ route('admin.forms.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<form method="POST"
      action="{{ $form ? route('admin.forms.update', $form) : route('admin.forms.store') }}"
      id="form-builder-form"
      x-data="formBuilder({{ $form ? json_encode(['schema' => $form->schema, 'settings' => $form->settings]) : '{}' }})">
    @csrf
    @if($form) @method('PUT') @endif
    {{-- Hidden schema & settings --}}
    <input type="hidden" name="schema" x-model="schemaJson">
    <input type="hidden" name="workflow_payload_map" x-model="payloadMapJson">

    {{-- Tabs --}}
    <ul class="nav nav-tabs mb-3" id="builderTabs">
        <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-fields"><i class="bi bi-layout-text-window me-1"></i>Fields</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings"><i class="bi bi-gear me-1"></i>Settings</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-share"><i class="bi bi-share me-1"></i>Share</button></li>
    </ul>

    <div class="tab-content">

        {{-- ── Fields Tab ──────────────────────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="tab-fields">
            <div class="row g-3">
                {{-- Field Palette --}}
                <div class="col-md-3">
                    <div class="card shadow-sm field-palette">
                        <div class="card-header py-2 small fw-semibold">Field Types</div>
                        <div class="card-body p-2">
                            @foreach([
                                ['text','Text Input','input-cursor-text'],
                                ['textarea','Text Area','text-paragraph'],
                                ['number','Number','123'],
                                ['email','Email','envelope'],
                                ['phone','Phone','telephone'],
                                ['date','Date','calendar-date'],
                                ['select','Dropdown','menu-button-wide'],
                                ['radio','Radio Buttons','ui-radios'],
                                ['checkbox','Checkboxes','ui-checks'],
                                ['rating','Rating / Stars','star-half'],
                                ['file','File Upload','file-earmark-arrow-up'],
                                ['section','Section Header','dash-lg'],
                            ] as [$type, $label, $icon])
                            <div class="field-type-btn btn btn-outline-secondary btn-sm w-100 text-start mb-1"
                                 @click="addField('{{ $type }}')">
                                <i class="bi bi-{{ $icon }} me-2"></i>{{ $label }}
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Canvas --}}
                <div class="col-md-5">
                    <div class="card shadow-sm">
                        <div class="card-header py-2 small fw-semibold d-flex justify-content-between">
                            <span><i class="bi bi-layout-text-window me-1"></i>Form Canvas</span>
                            <span class="text-muted" x-text="fields.length + ' field(s)'"></span>
                        </div>
                        <div class="card-body field-canvas p-2" id="field-canvas" style="min-height:300px;">
                            <div x-show="fields.length === 0" class="text-center text-muted py-5 small">
                                <i class="bi bi-arrow-left d-block display-6 mb-1"></i>Click a field type to add it
                            </div>
                            <template x-for="(field, idx) in fields" :key="field.id">
                                <div class="canvas-field" :data-idx="idx" @click="selectField(idx)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-grip-vertical text-muted" style="cursor:grab;"></i>
                                        <div class="flex-grow-1">
                                            <span class="fw-semibold small" x-text="field.label || '(untitled)'"></span>
                                            <span class="badge bg-light text-dark border ms-1 small" x-text="field.type"></span>
                                            <span x-show="field.required" class="text-danger ms-1 small">*</span>
                                        </div>
                                    </div>
                                    <button type="button" class="field-remove btn btn-sm btn-link text-danger p-0" @click.stop="removeField(idx)">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Config Panel --}}
                <div class="col-md-4">
                    <div class="card shadow-sm config-panel">
                        <div class="card-header py-2 small fw-semibold"><i class="bi bi-sliders me-1"></i>Field Config</div>
                        <div class="card-body" x-show="selectedField">
                            <template x-if="selectedField">
                                <div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold mb-1">Label</label>
                                        <input type="text" class="form-control form-control-sm" x-model="selectedField.label">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold mb-1">Field Name <small class="text-muted">(key)</small></label>
                                        <input type="text" class="form-control form-control-sm font-monospace" x-model="selectedField.name"
                                               placeholder="e.g. full_name">
                                    </div>
                                    <div class="mb-2" x-show="!['section','rating','checkbox','radio','select','file'].includes(selectedField.type)">
                                        <label class="form-label small fw-semibold mb-1">Placeholder</label>
                                        <input type="text" class="form-control form-control-sm" x-model="selectedField.placeholder">
                                    </div>
                                    <div class="mb-2" x-show="['select','radio','checkbox'].includes(selectedField.type)">
                                        <label class="form-label small fw-semibold mb-1">Options <small class="text-muted">(one per line)</small></label>
                                        <textarea class="form-control form-control-sm font-monospace" rows="4"
                                                  :value="(selectedField.options||[]).join('\n')"
                                                  @input="selectedField.options = $event.target.value.split('\n').filter(s=>s.trim())"></textarea>
                                    </div>
                                    {{-- Rating min/max --}}
                                    <div class="mb-2" x-show="selectedField.type === 'rating'">
                                        <div class="row g-1">
                                            <div class="col-6">
                                                <label class="form-label small fw-semibold mb-1">Min</label>
                                                <input type="number" class="form-control form-control-sm" x-model.number="selectedField.min" min="0" max="9">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-semibold mb-1">Max</label>
                                                <input type="number" class="form-control form-control-sm" x-model.number="selectedField.max" min="1" max="10">
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Number min/max --}}
                                    <div class="mb-2" x-show="selectedField.type === 'number'">
                                        <div class="row g-1">
                                            <div class="col-6">
                                                <label class="form-label small fw-semibold mb-1">Min Value</label>
                                                <input type="number" class="form-control form-control-sm"
                                                       x-model.number="selectedField.min"
                                                       placeholder="No limit">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-semibold mb-1">Max Value</label>
                                                <input type="number" class="form-control form-control-sm"
                                                       x-model.number="selectedField.max"
                                                       placeholder="No limit">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold mb-1">Help Text</label>
                                        <input type="text" class="form-control form-control-sm" x-model="selectedField.help_text">
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" x-model="selectedField.required" :id="'req-'+selectedField.id">
                                        <label class="form-check-label small" :for="'req-'+selectedField.id">Required</label>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold mb-1">Width</label>
                                        <select class="form-select form-select-sm" x-model="selectedField.width">
                                            <option value="full">Full</option>
                                            <option value="half">Half</option>
                                        </select>
                                    </div>
                                    {{-- Conditional Logic --}}
                                    <div class="mb-2" x-show="selectedField.type !== 'section'">
                                        <hr class="my-2">
                                        <label class="form-label small fw-semibold mb-1">
                                            <i class="bi bi-eye-slash me-1"></i>Show/Hide Condition
                                        </label>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox"
                                                   :id="'cond-'+selectedField.id"
                                                   :checked="selectedField.conditional !== null && selectedField.conditional !== undefined"
                                                   @change="toggleCondition($event.target.checked)">
                                            <label class="form-check-label small" :for="'cond-'+selectedField.id">Only show when…</label>
                                        </div>
                                        <div x-show="selectedField.conditional">
                                            <div class="mb-1">
                                                <label class="form-label small mb-0">Field</label>
                                                <select class="form-select form-select-sm"
                                                        x-model="selectedField.conditional && selectedField.conditional.field">
                                                    <option value="">— pick a field —</option>
                                                    <template x-for="f in otherFields" :key="f.name">
                                                        <option :value="f.name" x-text="f.label || f.name"></option>
                                                    </template>
                                                </select>
                                            </div>
                                            <div class="row g-1">
                                                <div class="col-6">
                                                    <label class="form-label small mb-0">Operator</label>
                                                    <select class="form-select form-select-sm"
                                                            x-model="selectedField.conditional && selectedField.conditional.operator">
                                                        <option value="equals">equals</option>
                                                        <option value="not_equals">not equals</option>
                                                        <option value="contains">contains</option>
                                                        <option value="not_empty">is not empty</option>
                                                        <option value="is_empty">is empty</option>
                                                    </select>
                                                </div>
                                                <div class="col-6"
                                                     x-show="selectedField.conditional && !['not_empty','is_empty'].includes(selectedField.conditional.operator)">
                                                    <label class="form-label small mb-0">Value</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                           x-model="selectedField.conditional && selectedField.conditional.value"
                                                           placeholder="Match value">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!selectedField" class="text-muted small text-center py-4">
                                <i class="bi bi-hand-index d-block display-6 mb-1"></i>Click a field to configure it
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Settings Tab ─────────────────────────────────────────────────── --}}
        <div class="tab-pane fade" id="tab-settings">
            <div class="card shadow-sm" style="max-width:680px;">
                <div class="card-body">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Form Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $form?->name) }}" required maxlength="150">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2">{{ old('description', $form?->description) }}</textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="type" class="form-select">
                                @foreach(['feedback','survey','request','intake'] as $t)
                                <option value="{{ $t }}" {{ old('type', $form?->type) === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Visibility</label>
                            <select name="visibility" class="form-select">
                                <option value="public"     {{ old('visibility', $form?->visibility) === 'public'     ? 'selected' : '' }}>Public (no login)</option>
                                <option value="private"    {{ old('visibility', $form?->visibility) === 'private'    ? 'selected' : '' }}>Private (login required)</option>
                                <option value="token_only" {{ old('visibility', $form?->visibility) === 'token_only' ? 'selected' : '' }}>Token Only (secret link)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Expires At <small class="text-muted">(optional)</small></label>
                            <input type="date" name="expires_at" class="form-control"
                                   value="{{ old('expires_at', $form?->expires_at?->format('Y-m-d')) }}">
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                               {{ old('is_active', $form?->is_active ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isActive">Form is active (accepting responses)</label>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3">Response Settings</h6>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Confirmation Message</label>
                        <input type="text" name="settings[confirmation_message]" class="form-control"
                               value="{{ old('settings.confirmation_message', $form?->settings['confirmation_message'] ?? 'Thank you! Your response has been recorded.') }}"
                               maxlength="500">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Redirect URL <small class="text-muted">(after submit, optional)</small></label>
                        <input type="url" name="settings[redirect_url]" class="form-control"
                               value="{{ old('settings.redirect_url', $form?->settings['redirect_url'] ?? '') }}"
                               placeholder="https://…">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Submit Button Label</label>
                        <input type="text" name="settings[submit_label]" class="form-control"
                               value="{{ old('settings.submit_label', $form?->settings['submit_label'] ?? 'Submit') }}"
                               maxlength="80">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Max Submissions <small class="text-muted">(leave blank for unlimited)</small></label>
                        <input type="number" name="settings[max_submissions]" class="form-control" min="1"
                               value="{{ old('settings.max_submissions', $form?->settings['max_submissions'] ?? '') }}">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="settings[allow_anonymous]" value="1" id="allowAnon"
                                       {{ ($form?->settings['allow_anonymous'] ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="allowAnon">Allow anonymous submissions</label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="settings[collect_email]" value="1" id="collectEmail"
                                       {{ ($form?->settings['collect_email'] ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="collectEmail">Collect submitter email</label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="settings[one_per_token]" value="1" id="onePerToken"
                                       {{ ($form?->settings['one_per_token'] ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="onePerToken">One submission per token link</label>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3">Notifications</h6>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notify users on new submission</label>
                        <select name="settings[notify_user_ids][]" class="form-select" multiple>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}"
                                {{ in_array($u->id, $form?->settings['notify_user_ids'] ?? []) ? 'selected' : '' }}>
                                {{ $u->name }}
                            </option>
                            @endforeach
                        </select>
                        <div class="form-text">Hold Ctrl/Cmd to select multiple</div>
                    </div>

                    @if($workflowTemplates->isNotEmpty())
                    <hr>
                    <h6 class="fw-semibold mb-3">Workflow Integration <small class="text-muted fw-normal">(optional)</small></h6>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Trigger Workflow Template</label>
                        <select name="workflow_template_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($workflowTemplates as $wt)
                            <option value="{{ $wt->id }}" {{ old('workflow_template_id', $form?->workflow_template_id) == $wt->id ? 'selected' : '' }}>
                                {{ $wt->display_name }}
                            </option>
                            @endforeach
                        </select>
                        <div class="form-text">When set, each submission will automatically create a workflow request.</div>
                    </div>
                    @endif

                </div>
            </div>
        </div>

        {{-- ── Share Tab ────────────────────────────────────────────────────── --}}
        <div class="tab-pane fade" id="tab-share">
            @if($form)
            <div class="card shadow-sm" style="max-width:580px;">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i>Form URL</h6>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control font-monospace small" readonly
                               value="{{ url('/forms/'.$form->slug) }}" id="formUrl">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('formUrl').value)">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <a href="{{ url('/forms/'.$form->slug) }}" target="_blank" class="btn btn-outline-primary">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3"><i class="bi bi-key me-1"></i>Generate Token Link</h6>
                    <div x-data="tokenGen('{{ route('admin.forms.tokens.generate', $form) }}')" class="mb-2">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <input type="text" class="form-control form-control-sm" x-model="label" placeholder="Label (e.g. Ticket #1234)">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" x-model="usesLimit" placeholder="Uses limit" min="1">
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control form-control-sm" x-model="expiresAt">
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" @click="generate()">
                            <i class="bi bi-plus-circle me-1"></i>Generate Link
                        </button>
                        <div x-show="tokenUrl" x-cloak class="mt-2">
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace small" readonly x-model="tokenUrl" id="tokenUrlInput">
                                <button class="btn btn-outline-secondary" type="button"
                                        @click="navigator.clipboard.writeText(tokenUrl)">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3"><i class="bi bi-table me-1"></i>Tokens Issued</h6>
                    @if($form->tokens->isEmpty())
                    <p class="text-muted small">No tokens generated yet.</p>
                    @else
                    <table class="table table-sm small">
                        <thead><tr><th>Label</th><th>Uses</th><th>Expires</th></tr></thead>
                        <tbody>
                            @foreach($form->tokens as $t)
                            <tr>
                                <td>{{ $t->label ?: '—' }}</td>
                                <td>{{ $t->uses_count }} / {{ $t->uses_limit ?? '∞' }}</td>
                                <td>{{ $t->expires_at?->format('d M Y') ?? '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
            @else
            <div class="alert alert-info">Save the form first to access sharing options.</div>
            @endif
        </div>

    </div>

    {{-- Save Button (always visible) --}}
    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary" @click="syncSchema()">
            <i class="bi bi-save me-1"></i>{{ $form ? 'Save Changes' : 'Create Form' }}
        </button>
        @if($form)
        <a href="{{ url('/forms/'.$form->slug) }}" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-eye me-1"></i>Preview
        </a>
        @endif
    </div>

</form>
@endsection

@push('scripts')
{{-- Sortable.js CDN --}}
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
function formBuilder(initial) {
    return {
        fields: initial.schema || [],
        selectedIdx: null,
        get selectedField() { return this.selectedIdx !== null ? this.fields[this.selectedIdx] : null; },
        get schemaJson()    { return JSON.stringify(this.fields); },
        get payloadMapJson(){ return JSON.stringify({}); },

        init() {
            const canvas = document.getElementById('field-canvas');
            const self   = this;
            Sortable.create(canvas, {
                animation: 150,
                handle: '.bi-grip-vertical',
                ghostClass: 'sortable-ghost',
                filter: '.field-remove',
                onEnd(evt) {
                    const moved = self.fields.splice(evt.oldIndex, 1)[0];
                    self.fields.splice(evt.newIndex, 0, moved);
                    if (self.selectedIdx === evt.oldIndex) self.selectedIdx = evt.newIndex;
                },
            });
        },

        addField(type) {
            const id = 'f' + Math.random().toString(36).substr(2, 6);
            const defaults = {
                text:     { label:'Text Field',    placeholder:'',    required:false, width:'full',  conditional:null },
                textarea: { label:'Text Area',     placeholder:'',    required:false, width:'full',  conditional:null },
                number:   { label:'Number',        placeholder:'',    required:false, width:'half',  conditional:null, min:null, max:null },
                email:    { label:'Email Address', placeholder:'',    required:false, width:'half',  conditional:null },
                phone:    { label:'Phone Number',  placeholder:'',    required:false, width:'half',  conditional:null },
                date:     { label:'Date',          placeholder:'',    required:false, width:'half',  conditional:null },
                select:   { label:'Dropdown',      options:[],        required:false, width:'full',  conditional:null },
                radio:    { label:'Single Choice', options:[],        required:false, width:'full',  conditional:null },
                checkbox: { label:'Multi Choice',  options:[],        required:false, width:'full',  conditional:null },
                rating:   { label:'Rating',        min:1, max:5,      required:false, width:'half',  conditional:null, style:'stars' },
                file:     { label:'File Upload',                      required:false, width:'full',  conditional:null },
                section:  { label:'Section Title',                    required:false, width:'full',  conditional:null },
            };
            this.fields.push({ id, type, name: type + '_' + this.fields.length, help_text:'', ...defaults[type] });
            this.selectedIdx = this.fields.length - 1;
        },

        selectField(idx) { this.selectedIdx = idx; },

        removeField(idx) {
            this.fields.splice(idx, 1);
            if (this.selectedIdx >= this.fields.length) this.selectedIdx = this.fields.length - 1;
            if (this.fields.length === 0) this.selectedIdx = null;
        },

        // All fields except the selected one — used for condition target picker
        get otherFields() {
            return this.fields.filter((f, i) => i !== this.selectedIdx && f.type !== 'section');
        },

        toggleCondition(enabled) {
            if (!this.selectedField) return;
            this.selectedField.conditional = enabled
                ? { field: '', operator: 'equals', value: '' }
                : null;
        },

        syncSchema() {
            // Hidden input is kept in sync via x-model="schemaJson" — no-op kept for backward compat
        },
    };
}

function tokenGen(generateUrl) {
    return {
        label: '',
        usesLimit: '',
        expiresAt: '',
        tokenUrl: '',

        async generate() {
            const r = await fetch(generateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ label: this.label, uses_limit: this.usesLimit || null, expires_at: this.expiresAt || null }),
            });
            const d = await r.json();
            this.tokenUrl = d.url || '';
        },
    };
}
</script>
@endpush
