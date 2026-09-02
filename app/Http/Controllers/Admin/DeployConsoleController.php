<?php

namespace App\Http\Controllers\Admin;

/*
 * SECURITY NOTES:
 *  1. The browser never supplies a command string. `run()` resolves the
 *     deploy_commands row belonging to this server and reads the body
 *     server-side, so run-deploy-commands is not a shell grant.
 *  2. The interactive shell IS an arbitrary-shell grant — it is the escape
 *     hatch, and it is the same permission plus its own audit trail.
 *  3. The SSH host always comes from the stored server row.
 *  4. Rate-limited (throttle:60,1 in routes/web.php).
 */

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DeployCommand;
use App\Models\DeployRun;
use App\Models\DeployServer;
use App\Services\Deploy\DeployRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeployConsoleController extends Controller
{
    public function __construct(private readonly DeployRunner $runner) {}

    /** Interactive SSH session — the operator types. */
    public function shell(Request $request, DeployServer $server): View|RedirectResponse
    {
        if ($guard = $this->unavailable($server)) {
            return $guard;
        }

        [$run, $token] = $this->runner->startShell($server, $request->user(), $request->ip());

        $this->audit("Opened SSH terminal on '{$server->name}' ({$server->target()})", $server);

        return $this->console($server, $run, $token, 'Interactive shell');
    }

    /** One of the server's own buttons. */
    public function run(Request $request, DeployServer $server, DeployCommand $command): View|RedirectResponse
    {
        abort_unless($command->deploy_server_id === $server->id, 404);

        if ($guard = $this->unavailable($server)) {
            return $guard;
        }

        if (! $command->is_active) {
            return back()->with('error', "Command '{$command->name}' is disabled.");
        }

        // One deploy at a time per server — two concurrent `git pull`s on the
        // same checkout is not a thing anyone wants to debug.
        if ($this->runner->hasRunInFlight($server)) {
            return back()->with('error', "Another command is already running on '{$server->name}'. Wait for it to finish.");
        }

        [$run, $token] = $this->runner->startCommand($server, $command, $request->user(), $request->ip());

        $this->audit("Ran '{$command->name}' on '{$server->name}'", $server);

        return $this->console($server, $run, $token, $command->name);
    }

    /** Ad-hoc connectivity probe, so an admin can prove an uploaded key works. */
    public function test(Request $request, DeployServer $server): View|RedirectResponse
    {
        if ($guard = $this->unavailable($server)) {
            return $guard;
        }

        [$run, $token] = $this->runner->startTest($server, $request->user(), $request->ip());

        $this->audit("Tested connection to '{$server->name}' ({$server->target()})", $server);

        return $this->console($server, $run, $token, 'Test connection');
    }

    /**
     * Called by the console page when an interactive session ends. Exec runs
     * are closed by the proxy instead — see Internal\DeployRunReportController.
     */
    public function disconnect(Request $request, DeployServer $server, DeployRun $run)
    {
        abort_unless($run->deploy_server_id === $server->id, 404);
        abort_unless(
            $run->user_id === $request->user()->id || $request->user()->can('manage-deploy-servers'),
            403
        );

        if ($run->mode === 'shell') {
            $run->finish('success');
        }

        return response()->json(['ok' => true, 'duration' => $run->fresh()->durationLabel()]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function console(DeployServer $server, DeployRun $run, string $token, string $label): View
    {
        $wsUrl = rtrim(config('telnet.ws_url', 'wss://noc.samirgroup.net/ws/telnet'), '/');

        return view('admin.deploy.console', [
            'server' => $server,
            'run' => $run,
            'label' => $label,
            'wsUrl' => "{$wsUrl}?token={$token}",
        ]);
    }

    /** Shared pre-flight for every launch path. */
    private function unavailable(DeployServer $server): ?RedirectResponse
    {
        if (! $server->is_active) {
            return back()->with('error', "Server '{$server->name}' is disabled.");
        }

        if (! $server->hasCredentials()) {
            return back()->with('error', "Server '{$server->name}' has no SSH credentials stored yet.");
        }

        return null;
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
