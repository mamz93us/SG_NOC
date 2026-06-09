<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\SnmpDevice;
use App\Services\CiscoTelnetClient;
use Illuminate\Console\Command;

/**
 * Pushes an SNMPv2c read-only community to every non-Meraki switch/router that
 * has saved Telnet credentials (run switches:telnet-creds first), then saves
 * the config and keeps the NOC-side polling community (MonitoredHost /
 * SnmpDevice) in sync. Idempotent — skips switches that already have it.
 *
 *   php artisan switches:snmp-config                 # dry-run: targets + commands
 *   php artisan switches:snmp-config --apply         # push community NOC + write mem
 *   php artisan switches:snmp-config --apply --ip=10.1.0.100
 *   php artisan switches:snmp-config --apply --community=noc   # case-sensitive!
 */
class SwitchSnmpConfig extends Command
{
    protected $signature = 'switches:snmp-config
        {--apply : Actually telnet in and configure the switches}
        {--ip= : Limit to a single device IP}
        {--community=NOC : SNMP RO community to set (case-sensitive)}
        {--no-write : Do not run "write memory" (change is lost on reload)}';

    protected $description = 'Configure SNMPv2c RO community on non-Meraki switches over Telnet';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $community = (string) $this->option('community');
        $save = ! $this->option('no-write');

        $targets = Device::whereIn('type', ['switch', 'router'])
            ->whereNotNull('ip_address')
            ->where(fn ($q) => $q->where('source', '!=', 'meraki')->orWhereNull('source'))
            ->whereHas('credentials', fn ($q) => $q->where('category', 'telnet'))
            ->when($this->option('ip'), fn ($q, $ip) => $q->where('ip_address', $ip))
            ->with('credentials')
            ->orderBy('ip_address')
            ->get();

        if ($targets->isEmpty()) {
            $this->warn('No switches with saved Telnet credentials. Run switches:telnet-creds first.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->info($targets->count()." switch(es) would get SNMP community \"{$community}\":");
            $this->table(['IP', 'Name', 'Branch'], $targets->map(fn ($d) => [
                $d->ip_address, $d->name, $d->branch?->name ?? '—',
            ])->all());
            $this->line('Commands sent per switch:');
            $this->line("  configure terminal\n   snmp-server community {$community} RO\n  end".($save ? "\n  write memory" : ''));
            $this->warn('Dry-run. Re-run with --apply to push.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Configuring SNMP community "%s" on %d switch(es)…', $community, $targets->count()));

        $results = [];
        foreach ($targets as $d) {
            $r = $this->configureDevice($d, $community, $save);

            $d->update([
                'telnet_reachable' => $r['reached'],
                'qos_probed_at' => now(),
                'qos_probe_error' => $r['ok'] ? null : $r['msg'],
            ]);

            if ($r['ok']) {
                $this->syncNocCommunity($d->ip_address, $community);
            }

            $results[] = [$d->ip_address, $d->name, $r['ok'] ? '✓' : '✗', $r['msg']];
        }

        $this->table(['IP', 'Name', 'OK', 'Result'], $results);
        $ok = collect($results)->where(2, '✓')->count();
        $this->info("Done. {$ok}/".count($results)." switch(es) have community \"{$community}\".");

        return self::SUCCESS;
    }

    /** @return array{ok:bool, reached:bool, msg:string} */
    private function configureDevice(Device $d, string $community, bool $save): array
    {
        $telnet = $d->credentials->firstWhere('category', 'telnet');
        $enable = $d->credentials->firstWhere('category', 'enable');
        if (! $telnet) {
            return ['ok' => false, 'reached' => false, 'msg' => 'no telnet credential'];
        }

        $c = new CiscoTelnetClient;
        try {
            $c->connect($d->ip_address, 23, 6.0);
            $pre = $c->waitFor(['Password:', 'Username:'], 8.0);
            if (stripos($pre, 'Username:') !== false) {
                return ['ok' => false, 'reached' => true, 'msg' => 'requires a username'];
            }
            $c->send((string) $telnet->password);
            $out = $c->waitForPrompt(7.0);

            if (! preg_match('/#\s*$/', $out)) { // not privileged → enable
                if (! $enable) {
                    return ['ok' => false, 'reached' => true, 'msg' => 'not privileged & no enable credential'];
                }
                $c->send('enable');
                $c->waitFor(['Password:'], 6.0);
                $c->send((string) $enable->password);
                $out = $c->waitForPrompt(7.0);
                if (! preg_match('/#\s*$/', $out)) {
                    return ['ok' => false, 'reached' => true, 'msg' => 'enable password rejected'];
                }
            }

            $c->send('terminal length 0');
            $c->waitForPrompt(5.0);

            // Idempotency: already configured?
            $c->send('show running-config | include snmp-server community '.$community);
            $check = $c->waitForPrompt(10.0);
            $already = stripos($check, 'snmp-server community '.$community) !== false;

            if (! $already) {
                $c->send('configure terminal');
                $c->waitForPrompt(6.0);
                $c->send('snmp-server community '.$community.' RO');
                $c->waitForPrompt(6.0);
                $c->send('end');
                $c->waitForPrompt(6.0);
            }

            // Verify from the running config.
            $c->send('show running-config | include snmp-server community '.$community);
            $verify = $c->waitForPrompt(10.0);
            $verified = stripos($verify, 'snmp-server community '.$community) !== false;

            $saved = false;
            if ($verified && $save && ! $already) {
                $c->send('write memory');
                $c->waitForPrompt(15.0);
                $saved = true;
            }

            try {
                $c->send('exit');
            } catch (\Throwable) {
            }

            if ($already) {
                return ['ok' => true, 'reached' => true, 'msg' => 'already present'];
            }
            if (! $verified) {
                return ['ok' => false, 'reached' => true, 'msg' => 'sent but not found in running-config'];
            }

            return ['ok' => true, 'reached' => true, 'msg' => $saved ? 'added + saved' : 'added (not saved to startup)'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reached' => false, 'msg' => $this->short($e->getMessage())];
        } finally {
            $c->close();
        }
    }

    /** Keep the NOC-side polling community equal to what we set on the switch. */
    private function syncNocCommunity(string $ip, string $community): void
    {
        // Use model saves (not query update) so the encrypted casts apply.
        foreach (MonitoredHost::where('ip', $ip)->get() as $h) {
            $h->snmp_community = $community;
            $h->save();
        }
        foreach (SnmpDevice::where('host', $ip)->get() as $s) {
            $s->snmp_community = $community;
            $s->save();
        }
    }

    private function short(?string $s): string
    {
        $s = trim((string) $s);

        return strlen($s) > 70 ? substr($s, 0, 67).'…' : $s;
    }
}
