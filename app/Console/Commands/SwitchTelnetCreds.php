<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\CiscoTelnetClient;
use Illuminate\Console\Command;

/**
 * Tries a set of candidate passwords over Telnet against every non-Meraki
 * switch/router and saves the working vty (login) and enable ("config t")
 * password per device as Credential rows — so the QoS switch module can use
 * them. Read-only on the switch (login + enable, no config changes).
 *
 *   # list the switches it would test (no connections):
 *   php artisan switches:telnet-creds
 *
 *   # test + save (single-quote passwords so the shell doesn't expand $):
 *   php artisan switches:telnet-creds --apply --password='pw1' --password='pw2'
 *   php artisan switches:telnet-creds --apply --ip=10.1.0.100 --password='pw1' --password='pw2'
 */
class SwitchTelnetCreds extends Command
{
    protected $signature = 'switches:telnet-creds
        {--apply : Telnet to each switch and save the working passwords}
        {--ip= : Limit to a single device IP}
        {--password=* : Candidate password(s) to try (repeat the flag per password)}';

    protected $description = 'Discover & save vty/enable Telnet passwords for non-Meraki switches (for the QoS module)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $candidates = array_values(array_filter((array) $this->option('password'), fn ($p) => $p !== ''));

        $targets = Device::whereIn('type', ['switch', 'router'])
            ->whereNotNull('ip_address')
            ->where(fn ($q) => $q->where('source', '!=', 'meraki')->orWhereNull('source'))
            ->when($this->option('ip'), fn ($q, $ip) => $q->where('ip_address', $ip))
            ->orderBy('ip_address')
            ->get();

        if ($targets->isEmpty()) {
            $this->warn('No matching switches/routers found.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->info($targets->count().' non-Meraki switch(es)/router(s) would be tested:');
            $this->table(['IP', 'Name', 'Branch'], $targets->map(fn ($d) => [
                $d->ip_address, $d->name, $d->branch?->name ?? '—',
            ])->all());
            $this->warn('Dry-run. Re-run with --apply and the passwords, e.g.:');
            $this->line("  php artisan switches:telnet-creds --apply --password='pw1' --password='pw2'");

            return self::SUCCESS;
        }

        if (empty($candidates)) {
            $this->error('Provide at least one --password to test (single-quote any with $).');

            return self::FAILURE;
        }

        $this->info(sprintf('Testing %d candidate password(s) against %d device(s)… (this telnets to each, be patient)',
            count($candidates), $targets->count()));

        $results = [];
        foreach ($targets as $d) {
            $r = $this->probeDevice($d->ip_address, $candidates);

            if ($r['login'] === null) {
                $d->update(['telnet_reachable' => false, 'qos_probed_at' => now(), 'qos_probe_error' => $r['error']]);
                $results[] = [$d->ip_address, $d->name, 'NONE', '—', $this->short($r['error'])];

                continue;
            }

            // Save vty (login) credential.
            $d->credentials()->updateOrCreate(
                ['category' => 'telnet'],
                ['title' => 'Telnet vty', 'password' => $candidates[$r['login']]],
            );

            // Save enable credential when one matched.
            if ($r['enable'] !== null) {
                $d->credentials()->updateOrCreate(
                    ['category' => 'enable'],
                    ['title' => 'Enable secret', 'password' => $candidates[$r['enable']]],
                );
            }

            $d->update([
                'telnet_reachable' => true,
                'qos_probed_at' => now(),
                'qos_probe_error' => $r['enable'] === null ? 'login ok; no enable password matched' : null,
            ]);

            $results[] = [
                $d->ip_address,
                $d->name,
                'pw'.($r['login'] + 1),
                $r['enable'] === null ? ($r['privileged'] ? 'login=priv' : 'NONE') : 'pw'.($r['enable'] + 1),
                $r['enable'] === null && ! $r['privileged'] ? 'enable not found' : 'ok',
            ];
        }

        $this->table(['IP', 'Name', 'vty (login)', 'enable (config t)', 'Notes'], $results);
        $ok = collect($results)->where(2, '!=', 'NONE')->count();
        $this->info("Done. {$ok}/".count($results).' device(s) had a working login password saved.');

        return self::SUCCESS;
    }

    /**
     * Try each candidate as the login password, then (if needed) each as the
     * enable password. Returns the matching candidate indexes (or null).
     *
     * @return array{login:?int, enable:?int, privileged:bool, error:?string}
     */
    private function probeDevice(string $ip, array $candidates): array
    {
        $loginIdx = null;
        $privileged = false;
        $lastErr = null;

        foreach ($candidates as $i => $pw) {
            $r = $this->attemptLogin($ip, $pw);
            if ($r['ok']) {
                $loginIdx = $i;
                $privileged = $r['privileged'];
                break;
            }
            $lastErr = $r['error'];
        }

        if ($loginIdx === null) {
            return ['login' => null, 'enable' => null, 'privileged' => false, 'error' => $lastErr];
        }

        $enableIdx = null;
        if ($privileged) {
            // Logged straight into privileged mode — reuse for config t.
            $enableIdx = $loginIdx;
        } else {
            foreach ($candidates as $i => $pw) {
                if ($this->attemptEnable($ip, $candidates[$loginIdx], $pw)) {
                    $enableIdx = $i;
                    break;
                }
            }
        }

        return ['login' => $loginIdx, 'enable' => $enableIdx, 'privileged' => $privileged, 'error' => null];
    }

    /** @return array{ok:bool, privileged:bool, error:?string} */
    private function attemptLogin(string $ip, string $password): array
    {
        $c = new CiscoTelnetClient;
        try {
            $c->connect($ip, 23, 6.0);
            $pre = $c->waitFor(['Password:', 'Username:'], 8.0);
            if (stripos($pre, 'Username:') !== false) {
                return ['ok' => false, 'privileged' => false, 'error' => 'requires a username (only passwords given)'];
            }
            $c->send($password);
            $out = $c->waitForPrompt(7.0);

            return ['ok' => true, 'privileged' => (bool) preg_match('/#\s*$/', $out), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'privileged' => false, 'error' => $e->getMessage()];
        } finally {
            $c->close();
        }
    }

    private function attemptEnable(string $ip, string $loginPass, string $enablePass): bool
    {
        $c = new CiscoTelnetClient;
        try {
            $c->connect($ip, 23, 6.0);
            $pre = $c->waitFor(['Password:', 'Username:'], 8.0);
            if (stripos($pre, 'Username:') !== false) {
                return false;
            }
            $c->send($loginPass);
            $out = $c->waitForPrompt(7.0);
            if (preg_match('/#\s*$/', $out)) {
                return true; // already privileged
            }
            $c->send('enable');
            $c->waitFor(['Password:'], 6.0);
            $c->send($enablePass);
            $out = $c->waitForPrompt(7.0);

            return (bool) preg_match('/#\s*$/', $out);
        } catch (\Throwable $e) {
            return false;
        } finally {
            $c->close();
        }
    }

    private function short(?string $s): string
    {
        $s = (string) $s;

        return strlen($s) > 60 ? substr($s, 0, 57).'…' : $s;
    }
}
