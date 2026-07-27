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
    protected $signature = 'identity:sync {--force : Force-release any stale lock before syncing}';

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
                    'status' => 'failed',
                    'error_message' => 'Manually force-reset via --force flag.',
                    'completed_at' => now(),
                ]);
            $this->info('Stale lock cleared.');
        }

        // ── Lock ───────────────────────────────────────────────────────
        // TTL must exceed the schedule interval so an overrunning run can't be
        // overlapped by the next scheduled one (which caused concurrent syncs +
        // DB serialization failures). 4h > healthy run (~1h) and the 2h cadence.
        $lock = Cache::lock('sync_identity_running', 14400);

        if (! $lock->get()) {
            $this->warn('A sync is already running. Use --force to override.');

            return self::FAILURE;
        }

        $this->info('Starting identity sync…');
        $log = ServiceSyncLog::start('identity');

        // Create IdentitySyncLog entry (shown in the Identity Sync Logs page)
        $detailedLog = IdentitySyncLog::create([
            'type' => 'full',
            'status' => 'started',
            'started_at' => now(),
        ]);

        try {
            // 1. Test connection
            $this->output->write('  → Authenticating with Microsoft Graph... ');
            $graph = new GraphService;
            $orgName = $graph->testConnection();
            $this->info("✓ Connected to: {$orgName}");

            $service = new IdentitySyncService($graph);

            $errors = [];
            $elapsed = fn ($t) => round(microtime(true) - $t, 1).'s';

            // 2. Licenses
            $this->output->write('  → Syncing licenses... ');
            $t = microtime(true);
            $licenseCount = $service->syncLicenses($errors);
            $this->info("✓ {$licenseCount} licenses ({$elapsed($t)})");

            // 3. Groups
            $this->output->write('  → Syncing groups... ');
            $t = microtime(true);
            $groupCount = $service->syncGroups($errors);
            $this->info("✓ {$groupCount} groups ({$elapsed($t)})");

            // 4. Users (heaviest)
            $this->output->write('  → Syncing users... ');
            $t = microtime(true);
            $userCount = $service->syncUsers($errors);
            $this->info("✓ {$userCount} users ({$elapsed($t)})");

            // 5. Group Memberships
            $this->output->write('  → Syncing group memberships... ');
            $t = microtime(true);
            $service->syncRelationships($errors);
            $this->info("✓ done ({$elapsed($t)})");

            // Show errors if any
            if (! empty($errors)) {
                $this->newLine();
                $this->warn('Non-fatal errors:');
                foreach ($errors as $err) {
                    $this->line("  ⚠ {$err}");
                }
            }

            $log->update([
                'status' => 'completed',
                'records_synced' => $userCount + $groupCount + $licenseCount,
                'completed_at' => now(),
            ]);

            $detailedLog->update([
                'status' => 'completed',
                'users_synced' => $userCount,
                'groups_synced' => $groupCount,
                'licenses_synced' => $licenseCount,
                'error_message' => empty($errors) ? null : implode("\n", $errors),
                'completed_at' => now(),
            ]);

            $this->newLine();
            $this->info("✅ Identity sync completed. Users: {$userCount} | Groups: {$groupCount} | Licenses: {$licenseCount}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $detailedLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->error('Identity sync failed: '.$e->getMessage());

            return self::FAILURE;

        } finally {
            $lock->release();
        }
    }
}
