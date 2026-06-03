<?php

namespace App\Console\Commands;

use App\Services\GdmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Probe the live GDMS tenant to confirm endpoint paths + response shapes that
 * the public API docs (a JS SPA at doc.grandstream.dev) don't expose in a
 * fetchable form. Run once against the real org to lock the ⚠️ PROBE-PENDING
 * endpoints in GdmsService (sip/server/list, template/*, device/detail
 * resource fields, task types) BEFORE wiring them to destructive UI actions.
 *
 *   php artisan gdms:probe                          # all read-only probes
 *   php artisan gdms:probe --mac=EC:74:D7:80:04:74   # also dump device/detail
 *
 * Read-only by design: it never calls device/add or task/add, so no reboot /
 * factory reset is ever triggered by this command.
 */
class GdmsProbe extends Command
{
    protected $signature = 'gdms:probe {--mac= : also fetch device/detail for this MAC (phone or UCM)}';

    protected $description = 'Probe the live GDMS tenant to confirm endpoint paths and response shapes (read-only)';

    public function handle(GdmsService $gdms): int
    {
        $this->info('Probing GDMS — read-only. No device/add or task/add is sent.');
        $this->newLine();

        $this->probe('orgs        (GET  /v1.0.0/org/list)', fn () => $gdms->listOrgs());
        $this->probe('sites       (GET  /v1.0.0/site/list)', fn () => $gdms->listSites());
        $this->probe('devices     (POST /v1.0.0/device/list)', fn () => $gdms->listAllPhoneDevices());
        $this->probe('sipAccounts (POST /v1.0.0/sip/account/list)', fn () => $this->accountRows($gdms->listSipAccounts()));
        $this->probe('sipServers  (POST /v1.0.0/sip/server/list)   [PROBE-PENDING]', fn () => $gdms->listSipServers());
        $this->probe('templates   (POST /v1.0.0/template/list)     [PROBE-PENDING]', fn () => $gdms->listTemplates());

        if ($mac = $this->option('mac')) {
            $this->newLine();
            $this->info("Fetching device/detail for {$mac} (blocks up to ~60s while the device reports back)...");
            try {
                $data = $gdms->getDeviceDetailRaw($mac);
                if ($data === null) {
                    $this->warn('  device/detail: device did not report back (offline?).');
                } else {
                    $this->line('  data keys: '.implode(', ', array_keys($data)));
                    // Highlight the fields we care about for the PBX status page + accounts.
                    foreach (['memory', 'memUsage', 'storage', 'diskUsage', 'cpu', 'cpuUsage', 'sipAccountList', 'fxsPortList'] as $k) {
                        if (array_key_exists($k, $data)) {
                            $sample = is_array($data[$k]) ? json_encode($data[$k], JSON_UNESCAPED_SLASHES) : $data[$k];
                            $this->line("    ✓ {$k}: ".Str::limit((string) $sample, 200));
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->error('  device/detail threw: '.$e->getMessage());
            }
        }

        // ── Candidate-path discovery for the PROBE-PENDING endpoints ──────
        // The template / account-assign / sip-server / config-push paths only
        // live in the GDMS SPA reference. Try plausible paths and report which
        // actually exist on this tenant. A "✓ EXISTS" line is the real path —
        // put it in config/services.gdms.endpoints (or the EP_* constants).
        $this->newLine();
        $this->info('Discovering PROBE-PENDING endpoint paths (which candidates exist?)...');
        $listBody = ['pageNum' => 1, 'pageSize' => 1, 'orgId' => (int) (config('services.gdms.org_id') ?: 0)];
        $groups = [
            // template/group/list is confirmed; find the rest of the family.
            'template detail/update/assign (group/*)' => [
                '/v1.0.0/template/group/get', '/v1.0.0/template/group/detail', '/v1.0.0/template/group/info',
                '/v1.0.0/template/group/update', '/v1.0.0/template/group/edit', '/v1.0.0/template/group/save',
                '/v1.0.0/template/group/add', '/v1.0.0/template/group/assign', '/v1.0.0/template/group/push',
                '/v1.0.0/template/group/bind', '/v1.0.0/template/group/sendToDevice', '/v1.0.0/template/group/applyToDevice',
                '/v1.0.0/template/group/param/list', '/v1.0.0/template/group/delete',
            ],
            'sip account create / assign-to-device' => [
                '/v1.0.0/sip/account/add', '/v1.0.0/sip/account/update', '/v1.0.0/sip/account/edit',
                '/v1.0.0/sip/account/assignDevice', '/v1.0.0/sip/account/bindDevice', '/v1.0.0/sip/account/device/add',
                '/v1.0.0/device/account/assign', '/v1.0.0/device/account/add', '/v1.0.0/device/account/bind',
                '/v1.0.0/device/sipaccount/add',
            ],
        ];
        foreach ($groups as $label => $paths) {
            $this->line("· {$label}:");
            foreach ($paths as $p) {
                $this->line("    {$p}  →  ".$this->classify($gdms->probeEndpoint($p, $listBody)));
            }
        }

        $this->newLine();
        $this->info('Task types currently configured (confirm against the GDMS UI before wiring buttons):');
        $this->line('  REBOOT        = '.GdmsService::TASK_REBOOT.'   (confirmed)');
        $this->line('  FACTORY_RESET = '.(int) config('services.gdms.task_factory_reset', GdmsService::TASK_FACTORY_RESET).'   (PROBE-PENDING — verify!)');
        $this->line('  UPGRADE       = '.GdmsService::TASK_UPGRADE.'   (PROBE-PENDING)');
        $this->newLine();
        $this->comment('Update the EP_* paths / TASK_* values in app/Services/GdmsService.php for any [ERR] line above.');

        return self::SUCCESS;
    }

    private function probe(string $label, callable $fn): void
    {
        try {
            $result = $fn();
            $count = is_array($result) ? count($result) : 0;
            $this->info("[OK]  {$label} — {$count} row(s)");
            if ($count > 0 && is_array($result)) {
                $first = $result[array_key_first($result)];
                if (is_array($first)) {
                    $this->line('      keys: '.implode(', ', array_keys($first)));
                }
            }
        } catch (\Throwable $e) {
            $this->warn("[ERR] {$label} — ".$e->getMessage());
        }
    }

    /** listSipAccounts() returns the full response envelope; pull the list out for counting. */
    private function accountRows(array $resp): array
    {
        return $resp['data']['result'] ?? $resp['result'] ?? $resp['data'] ?? [];
    }

    /** Classify a probeEndpoint() response: does the route exist on this tenant? */
    private function classify(array $r): string
    {
        if (isset($r['_exception'])) {
            return 'ERR '.Str::limit($r['_exception'], 90);
        }
        if (($r['status'] ?? null) === 404 || ($r['error'] ?? '') === 'Not Found') {
            return '404 no-route';
        }
        if (array_key_exists('retCode', $r)) {
            $rc = $r['retCode'];

            return $rc === 0
                ? '✓ EXISTS (retCode 0 — this is the path!)'
                : "✓ EXISTS (retCode {$rc}: ".($r['msg'] ?? '').')';
        }

        return 'unknown: '.Str::limit(json_encode($r), 90);
    }
}
