<?php

namespace App\Services\Deploy;

use App\Models\DeployCommand;
use App\Models\DeployRun;
use App\Models\DeployServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Opens a run and mints the short-lived token the Node proxy redeems.
 *
 * PHP never speaks SSH here. It writes the connection details into the cache
 * under the key the proxy already knows (`telnet_token:{token}`), and the proxy
 * fetches them over loopback at /internal/telnet-token/{token}. That is what
 * makes the output stream live and costs us no queue worker.
 *
 * Kept out of the controller on purpose: app/Jobs/Infra/RunSshCommandJob is an
 * empty stub in the workflow step registry, and this is what it should call.
 */
class DeployRunner
{
    /** Interactive shell — the operator types, nothing is captured. */
    public function startShell(DeployServer $server, User $user, ?string $clientIp = null): array
    {
        $run = $this->openRun($server, null, $user, 'shell', $clientIp);

        return [$run, $this->mintToken($server, $run, null, $user)];
    }

    /** Button press — streams to the browser, transcript reported back by the proxy. */
    public function startCommand(DeployServer $server, DeployCommand $command, User $user, ?string $clientIp = null): array
    {
        $run = $this->openRun($server, $command, $user, 'exec', $clientIp);

        return [$run, $this->mintToken($server, $run, $command->resolvedCommand(), $user)];
    }

    /**
     * Ad-hoc connectivity check. Not stored as a command row — this is the
     * "did my key actually work" button on the server page.
     */
    public function startTest(DeployServer $server, User $user, ?string $clientIp = null): array
    {
        $run = $this->openRun($server, null, $user, 'exec', $clientIp, 'Test connection', 60);

        return [$run, $this->mintToken($server, $run, 'echo ok; uname -a; whoami; pwd', $user, 60)];
    }

    /**
     * Is something genuinely still in flight against this server?
     *
     * A row past its own deadline is dead whether or not deploy-runs:reap has
     * caught up yet, so it must not hold the lock — otherwise a report that
     * never arrives locks the server out of deploying for the command's whole
     * timeout plus the reaper's grace, which is exactly what a stuck run did.
     */
    public function hasRunInFlight(DeployServer $server): bool
    {
        return $server->runs()
            ->running()
            ->where('mode', 'exec')
            ->get()
            ->contains(fn (DeployRun $run) => ! $run->isPastDeadline());
    }

    // ─── Internals ────────────────────────────────────────────────

    private function openRun(
        DeployServer $server,
        ?DeployCommand $command,
        User $user,
        string $mode,
        ?string $clientIp,
        ?string $label = null,
        ?int $timeout = null,
    ): DeployRun {
        return DeployRun::create([
            'deploy_server_id' => $server->id,
            'deploy_command_id' => $command?->id,
            'user_id' => $user->id,
            'mode' => $mode,
            'status' => 'running',
            'command_label' => $label ?? $command?->name,
            'timeout_seconds' => $timeout ?? $command?->timeout_seconds ?? 600,
            'client_ip' => $clientIp,
            'started_at' => now(),
        ]);
    }

    /**
     * The payload the proxy consumes. `exec` null means an interactive shell —
     * that is the existing behaviour, so device and telnet sessions are
     * unaffected by these extra keys.
     */
    private function mintToken(
        DeployServer $server,
        DeployRun $run,
        ?string $exec,
        User $user,
        ?int $timeout = null,
    ): string {
        $token = Str::random(40);

        Cache::put("telnet_token:{$token}", [
            'host' => $server->hostname,   // ALWAYS the stored host, never user input
            'port' => $server->port ?: 22,
            'protocol' => 'ssh',
            'username' => $server->username,
            'password' => $server->usesKey() ? null : $server->password,
            'privateKey' => $server->usesKey() ? $server->private_key : null,
            'passphrase' => $server->key_passphrase ?: null,
            'exec' => $exec,
            'runId' => $run->id,
            'reportUrl' => "/internal/deploy-run/{$run->id}/complete",
            'timeout' => $timeout ?? $run->timeout_seconds,
            // A token carrying a private key must not stay redeemable after
            // first use — see TelnetTokenController.
            'singleUse' => true,
            'user_id' => $user->id,
        ], now()->addMinutes((int) config('telnet.token_ttl', 5)));

        return $token;
    }
}
