<?php

namespace App\Console\Commands;

use App\Models\IdentitySyncLog;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use App\Services\Identity\IdentitySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncIdentity extends Command
{
    protected $signature   = 'identity:sync {--force : Force-release any stale lock before syncing}';
    protected $description = 'Synchronise users, licenses, and groups from Microsoft Entra ID (Graph API).';

    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $settings = Setting::get();

        if (! $settings->identity_sync_enabled) {
            $this->warn('Identity sync is disabled in Settings — skipping.');
            return self::SUCCESS;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            $this->error('Microsoft Graph credentials are not configured in Settings.');
            return self::FAILURE;
        }

        // ── Force-release stale lock if --force flag given ─────────────
        if ($this->option('force')) {
            Cache::lock('sync_identity_running')->forceRelease();
            IdentitySyncLog::where('status', 'started')
                ->update([
                    'status'        => 'failed',
                    'error_message' => 'Manually force-reset via --force flag.',
                    'completed_at'  => now(),
                ]);
            $this->info('Stale lock cleared.');
        }

        // ── Lock ───────────────────────────────────────────────────────
        $lock = Cache::lock('sync_identity_running', 7200);

        if (! $lock->get()) {
            $this->warn('A sync is already running. Use --force to override.');
            return self::FAILURE;
        }

        $this->info('Starting identity sync…');
        $log = ServiceSyncLog::start('identity');

        try {
            // 1. Test connection
            $this->output->write('  → Authenticating with Microsoft Graph... ');
            $graph   = new GraphService();
            $orgName = $graph->testConnection();
            $this->info("✓ Connected to: {$orgName}");

            $service = new IdentitySyncService($graph);

            $errors = [];

            // 2. Licenses
            $this->output->write('  → Syncing licenses... ');
            $licenseCount = $service->syncLicenses($errors);
            $this->info("✓ {$licenseCount} licenses");

            // 3. Groups
            $this->output->write('  → Syncing groups... ');
            $groupCount = $service->syncGroups($errors);
            $this->info("✓ {$groupCount} groups");

            // 4. Users (heaviest)
            $this->output->write('  → Syncing users... ');
            $userCount = $service->syncUsers($errors);
            $this->info("✓ {$userCount} users");

            // 5. Group Memberships
            $this->output->write('  → Syncing group memberships... ');
            $service->syncRelationships($errors);
            $this->info('✓ done');

            // 6. Managers
            $this->output->write('  → Syncing manager relationships... ');
            $service->syncManagers($errors);
            $this->info('✓ done');

            // Show errors if any
            if (! empty($errors)) {
                $this->newLine();
                $this->warn('Non-fatal errors:');
                foreach ($errors as $err) {
                    $this->line("  ⚠ {$err}");
                }
            }

            $log->update([
                'status'         => 'completed',
                'records_synced' => $userCount + $groupCount + $licenseCount,
                'completed_at'   => now(),
            ]);

            $this->newLine();
            $this->info("✅ Identity sync completed. Users: {$userCount} | Groups: {$groupCount} | Licenses: {$licenseCount}");
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            $this->error('Identity sync failed: ' . $e->getMessage());
            return self::FAILURE;

        } finally {
            $lock->release();
        }
    }
}
