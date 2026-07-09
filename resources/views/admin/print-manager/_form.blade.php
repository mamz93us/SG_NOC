@php
    $editing = isset($cupsPrinter);

    // Map of system printers → prefill data for the Alpine auto-fill on create.
    $printerMap = ($printers ?? collect())->mapWithKeys(fn ($p) => [$p->id => [
        'name'      => $p->printer_name,
        'ip'        => $p->ip_address,
        'branch_id' => $p->branch_id,
        'location'  => $p->locationLabel() !== '—' ? $p->locationLabel() : '',
        'queue'     => \Illuminate\Support\Str::slug((string) $p->printer_name) ?: ('printer-' . $p->id),
    ]])->toArray();
@endphp

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong>{{ $editing ? 'Edit' : 'Add' }} CUPS Printer</strong>
    </div>
    <div class="card-body" x-data="{
            protocol: '{{ old('protocol', $editing ? $cupsPrinter->protocol : 'ipp') }}',
            printers: {{ \Illuminate\Support\Js::from($printerMap) }},
            selected: '{{ old('printer_id', $editing ? $cupsPrinter->printer_id : '') }}',
            applyPrinter() {
                const p = this.printers[this.selected];
                if (!p) return;
                if (this.$refs.name)     this.$refs.name.value     = p.name ?? '';
                if (this.$refs.queue)    this.$refs.queue.value    = p.queue ?? '';
                if (this.$refs.ip)       this.$refs.ip.value       = p.ip ?? '';
                if (this.$refs.location && p.location) this.$refs.location.value = p.location;
                if (this.$refs.branch && p.branch_id)  this.$refs.branch.value   = p.branch_id;
            }
        }">
        <div class="row g-3">

            @if(! $editing)
            {{-- System Printer (source of truth — add only from printers already in the system) --}}
            <div class="col-12">
                <label class="form-label fw-semibold">System Printer <span class="text-danger">*</span></label>
                <select name="printer_id" class="form-select" required x-model="selected" @change="applyPrinter()">
                    <option value="">— Select a printer already in the system —</option>
                    @foreach($printers as $p)
                        <option value="{{ $p->id }}" {{ old('printer_id') == $p->id ? 'selected' : '' }}>
                            {{ $p->printer_name }}{{ $p->ip_address ? ' — '.$p->ip_address : '' }}{{ $p->branch ? ' ('.$p->branch->name.')' : '' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">
                    Only printers registered under <a href="{{ route('admin.printers.index') }}">Printers</a> are listed.
                    Selecting one fills the fields below — add the device there first if it's missing.
                </div>
                @error('printer_id') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            @else
            <input type="hidden" name="printer_id" value="{{ $cupsPrinter->printer_id }}">
            @if($cupsPrinter->printer)
            <div class="col-12">
                <label class="form-label fw-semibold">System Printer</label>
                <input type="text" class="form-control" value="{{ $cupsPrinter->printer->printer_name }}" disabled>
            </div>
            @endif
            @endif

            {{-- Name --}}
            <div class="col-md-6">
                <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                <input type="text" name="name" x-ref="name" class="form-control"
                       value="{{ old('name', $editing ? $cupsPrinter->name : '') }}" required>
                @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Queue Name --}}
            <div class="col-md-6">
                <label class="form-label fw-semibold">Queue Name <span class="text-danger">*</span></label>
                <input type="text" name="queue_name" x-ref="queue" class="form-control font-monospace"
                       value="{{ old('queue_name', $editing ? $cupsPrinter->queue_name : '') }}"
                       pattern="[a-zA-Z0-9_-]+" required
                       placeholder="e.g. branch1-hp-lj">
                <div class="form-text">Letters, numbers, hyphens, underscores only.</div>
                @error('queue_name') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- IP Address --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold">Printer IP Address <span class="text-danger">*</span></label>
                <input type="text" name="ip_address" x-ref="ip" class="form-control"
                       value="{{ old('ip_address', $editing ? $cupsPrinter->ip_address : '') }}" required
                       placeholder="e.g. 10.0.1.100">
                @error('ip_address') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Port --}}
            <div class="col-md-2">
                <label class="form-label fw-semibold">Port</label>
                <input type="number" name="port" class="form-control"
                       value="{{ old('port', $editing ? $cupsPrinter->port : 631) }}"
                       min="1" max="65535">
                @error('port') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Protocol --}}
            <div class="col-md-3">
                <label class="form-label fw-semibold">Protocol <span class="text-danger">*</span></label>
                <select name="protocol" class="form-select" x-model="protocol">
                    <option value="ipp">IPP</option>
                    <option value="ipps">IPPS (SSL)</option>
                    <option value="socket">Socket / JetDirect</option>
                    <option value="lpd">LPD</option>
                </select>
                @error('protocol') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- IPP Path (shown for ipp/ipps) --}}
            <div class="col-md-3" x-show="protocol === 'ipp' || protocol === 'ipps'" x-cloak>
                <label class="form-label fw-semibold">IPP Path</label>
                <input type="text" name="ipp_path" class="form-control font-monospace"
                       value="{{ old('ipp_path', $editing ? $cupsPrinter->ipp_path : '/ipp/print') }}"
                       placeholder="/ipp/print">
                @error('ipp_path') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Branch --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold">Branch</label>
                <select name="branch_id" x-ref="branch" class="form-select">
                    <option value="">— None —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                            {{ old('branch_id', $editing ? $cupsPrinter->branch_id : '') == $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Driver --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold">CUPS Driver</label>
                <input type="text" name="driver" class="form-control"
                       value="{{ old('driver', $editing ? $cupsPrinter->driver : 'everywhere') }}"
                       placeholder="everywhere">
                <div class="form-text">Use "everywhere" for driverless IPP printing.</div>
                @error('driver') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Location --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold">Location</label>
                <input type="text" name="location" x-ref="location" class="form-control"
                       value="{{ old('location', $editing ? $cupsPrinter->location : '') }}"
                       placeholder="e.g. 2nd Floor, Room 204">
                @error('location') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            {{-- Checkboxes --}}
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input type="hidden" name="is_shared" value="0">
                    <input class="form-check-input" type="checkbox" name="is_shared" value="1" id="is_shared"
                           {{ old('is_shared', $editing ? $cupsPrinter->is_shared : true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_shared">Shared printer (visible to network clients)</label>
                </div>
                <div class="form-check">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                           {{ old('is_active', $editing ? $cupsPrinter->is_active : true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_active">Active (enabled in CUPS)</label>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <button type="submit" class="btn btn-success">
        <i class="bi bi-check-lg me-1"></i>{{ $editing ? 'Update Printer' : 'Add Printer' }}
    </button>
    <a href="{{ route('admin.print-manager.index') }}" class="btn btn-secondary">Cancel</a>
</div>
