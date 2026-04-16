<?php

namespace App\Console\Commands;

use App\Models\SslCertificate;
use App\Services\Dns\AcmeService;
use App\Services\Dns\GoDaddyService;
use Illuminate\Console\Command;

class RenewExpiringCertsCommand extends Command
{
    protected $signature   = 'dns:renew-expiring-certs {--dry-run : Show what would be renewed without actually renewing}';
    protected $description = 'Renew SSL certificates expiring within 14 days';

    public function handle(): int
    {
        $expiring = SslCertificate::with('account')
            ->where('status', 'valid')
            ->where('auto_renew', true)
            ->where('expires_at', '<=', now()->addDays(14))
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No certificates expiring within 14 days.');
            return self::SUCCESS;
        }

        $this->info("Found {$expiring->count()} certificate(s) to renew:");
        $this->newLine();

        $rows = $expiring->map(fn($c) => [
            $c->fqdn,
            $c->expires_at?->format('Y-m-d') ?? '-',
            $c->account?->label ?? '-',
            $c->auto_renew ? 'Yes' : 'No',
        ])->toArray();

        $this->table(['FQDN', 'Expires', 'Account', 'Auto-Renew'], $rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode — no certificates were renewed.');
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($expiring as $cert) {
            $this->output->write("  Renewing {$cert->fqdn}... ");

            try {
                $account = $cert->account;
                if (!$account || !$account->is_active) {
                    $this->line('<fg=yellow>SKIP (account inactive)</>');
                    continue;
                }

                $godaddy = new GoDaddyService($account);
                $acme    = new AcmeService($godaddy);
                $renewed = $acme->renewCertificate($cert, $account);

                $this->line("<fg=green>OK</> → expires " . $renewed->expires_at?->format('Y-m-d'));
            } catch (\Throwable $e) {
                $this->line("<fg=red>FAILED</> — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('Done.');
        return self::SUCCESS;
    }
}
