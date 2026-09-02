<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DeployCommand;
use App\Models\DeployServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The per-server button list. Every server has different deploy steps, so this
 * is free-form: the body may be an inline snippet or a call to a script that
 * lives on the target host.
 *
 * Gated by manage-deploy-servers — defining one of these is defining arbitrary
 * remote shell, which is a heavier privilege than pressing the button later.
 */
class DeployCommandController extends Controller
{
    public function store(Request $request, DeployServer $server): RedirectResponse
    {
        $command = $server->commands()->create($this->validated($request));

        $this->audit("Added command '{$command->name}' to '{$server->name}'", $server);

        return back()->with('success', "Command '{$command->name}' added.");
    }

    public function update(Request $request, DeployServer $server, DeployCommand $command): RedirectResponse
    {
        abort_unless($command->deploy_server_id === $server->id, 404);

        $command->update($this->validated($request));

        $this->audit("Updated command '{$command->name}' on '{$server->name}'", $server);

        return back()->with('success', "Command '{$command->name}' updated.");
    }

    public function destroy(DeployServer $server, DeployCommand $command): RedirectResponse
    {
        abort_unless($command->deploy_server_id === $server->id, 404);

        $name = $command->name;
        $command->delete();

        $this->audit("Deleted command '{$name}' from '{$server->name}'", $server);

        return back()->with('success', "Command '{$name}' deleted.");
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'string', 'in:'.implode(',', DeployCommand::KINDS)],
            'command' => ['required', 'string', 'max:10000'],
            'working_directory' => ['nullable', 'string', 'max:255'],
            'timeout_seconds' => ['required', 'integer', 'between:5,7200'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'confirm_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'confirm_required' => $request->boolean('confirm_required'),
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->input('sort_order', 0),
        ];
    }

    private function audit(string $message, DeployServer $server): void
    {
        try {
            ActivityLog::log('DEPLOY_SERVER', $message, 'success', $server->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
