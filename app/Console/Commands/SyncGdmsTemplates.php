<?php

namespace App\Console\Commands;

use App\Models\GdmsTemplate;
use App\Services\GdmsService;
use Illuminate\Console\Command;

/**
 * Sync GDMS configuration templates into the local gdms_templates cache so the
 * template-manager UI can list them without hitting the GDMS API every load.
 * Scheduled daily; also invoked on demand from the template manager.
 */
class SyncGdmsTemplates extends Command
{
    protected $signature = 'gdms:sync-templates';

    protected $description = 'Sync GDMS configuration templates into the local cache';

    public function handle(GdmsService $gdms): int
    {
        try {
            $templates = $gdms->listTemplates();
        } catch (\Throwable $e) {
            $this->error('GDMS listTemplates failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $count = 0;
        foreach ($templates as $t) {
            $id = (string) ($t['id'] ?? $t['templateId'] ?? $t['groupId'] ?? '');
            if ($id === '') {
                continue;
            }

            GdmsTemplate::updateOrCreate(
                ['gdms_template_id' => $id],
                [
                    'name' => $t['name'] ?? $t['templateName'] ?? $t['groupName'] ?? ('Template '.$id),
                    'type' => $t['type'] ?? (isset($t['groupId']) ? 'group' : (isset($t['siteId']) ? 'site' : 'model')),
                    'model' => $t['model'] ?? $t['deviceType'] ?? null,
                    'scope_ref' => $t['siteId'] ?? $t['groupId'] ?? null,
                    'raw' => $t,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        $this->info("Synced {$count} GDMS template(s).");

        return self::SUCCESS;
    }
}
