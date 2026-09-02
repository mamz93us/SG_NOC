{{--
    Shared field set for the add/edit command modals on the server page.
    $command is null when adding.
--}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Button label <span class="text-danger">*</span></label>
        <input type="text" name="name" value="{{ $command->name ?? '' }}"
               class="form-control" placeholder="Deploy" maxlength="100" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Kind</label>
        <select name="kind" class="form-select">
            @foreach(\App\Models\DeployCommand::KINDS as $kind)
                <option value="{{ $kind }}" @selected(($command->kind ?? 'custom') === $kind)>{{ ucfirst($kind) }}</option>
            @endforeach
        </select>
        <small class="text-muted">Button colour and icon.</small>
    </div>
    <div class="col-md-3">
        <label class="form-label">Timeout (seconds)</label>
        <input type="number" name="timeout_seconds" value="{{ $command->timeout_seconds ?? 600 }}"
               class="form-control" min="5" max="7200" required>
    </div>

    <div class="col-12">
        <label class="form-label">Command <span class="text-danger">*</span></label>
        <textarea name="command" class="form-control font-monospace" rows="4" maxlength="10000" required
                  placeholder="git pull &amp;&amp; composer install --no-dev -o &amp;&amp; php artisan migrate --force">{{ $command->command ?? '' }}</textarea>
        <small class="text-muted">
            Runs in a login-less SSH session as
            <code>{{ $server->username }}</code>. Either an inline snippet or a script on
            the server (<code>/opt/sg/deploy.sh</code>) — whichever suits this host.
        </small>
    </div>

    <div class="col-md-6">
        <label class="form-label">Working directory</label>
        <input type="text" name="working_directory" value="{{ $command->working_directory ?? '' }}"
               class="form-control font-monospace" maxlength="255"
               placeholder="{{ $server->working_directory ?: '/home/azureuser/app' }}">
        <small class="text-muted">
            Blank uses the server default{{ $server->working_directory ? ' (' . $server->working_directory . ')' : '' }}.
        </small>
    </div>
    <div class="col-md-3">
        <label class="form-label">Sort order</label>
        <input type="number" name="sort_order" value="{{ $command->sort_order ?? 0 }}"
               class="form-control" min="0" max="9999">
    </div>
    <div class="col-md-3 d-flex flex-column justify-content-center">
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" value="1" name="is_active"
                   id="active{{ $command->id ?? 'New' }}" @if($command->is_active ?? true) checked @endif>
            <label class="form-check-label small" for="active{{ $command->id ?? 'New' }}">Enabled</label>
        </div>
        <div class="form-check form-switch">
            <input type="hidden" name="confirm_required" value="0">
            <input class="form-check-input" type="checkbox" value="1" name="confirm_required"
                   id="confirm{{ $command->id ?? 'New' }}" @if($command->confirm_required ?? false) checked @endif>
            <label class="form-check-label small" for="confirm{{ $command->id ?? 'New' }}">Ask first</label>
        </div>
    </div>
</div>
