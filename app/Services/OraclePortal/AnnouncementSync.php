<?php

namespace App\Services\OraclePortal;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\OraclePortal\PortalSetting;
use App\Support\AnnouncementCache;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies Oracle's announcements into the NOC's own table, leaving anything
 * typed here alone.
 *
 * Manual notices are out of reach by construction: every query below is scoped
 * to `source = 'oracle'`, and the column defaults to 'manual', so a row nobody
 * marked as Oracle's can never be updated, unpublished or withdrawn here.
 *
 * What a synced notice actually contains is worth being plain about. Oracle
 * gives a subject, two dates and an image FILENAME — the DESCRIPTION column is
 * NULL for all 61 announcements, and the image bytes are unreachable, because
 * every path on that host outside /api answers 403. The picture is the real
 * notice. So these rows carry a bilingual title and its dates and nothing
 * else, and the mapper writes `body` anyway so that the day Oracle fills the
 * column in, the next pull fills it here with no code change.
 *
 * A row that leaves the feed is withdrawn, never deleted: announcement_reads
 * has no cascade, so deleting would orphan read state, and by then the row may
 * be somebody's edit.
 */
class AnnouncementSync
{
    /**
     * No floor for a first run, unlike the people feeds.
     *
     * 61 announcements is what Oracle holds today, but a noticeboard is
     * genuinely allowed to be short — a company with four notices is not a
     * broken response, and a floor would refuse it on every run forever,
     * because the baseline it is waiting for is only ever set by a run that
     * succeeds. An empty response is still always refused, and once one run
     * has succeeded the half-of-baseline rule does the real work.
     */
    private const FLOOR = 0;

    public function __construct(private PortalApiClient $api) {}

    /**
     * @return array{created:int,updated:int,unchanged:int,kept:int,removed:int,restored:int,rows:int,dry_run:bool}
     *
     * @throws RuntimeException when the response is too small to act on
     */
    public function sync(bool $dryRun = false, ?int $userId = null, ?PortalSetting $settings = null): array
    {
        $settings ??= PortalSetting::get();

        if ($issue = $settings->configurationIssue()) {
            throw new RuntimeException($issue);
        }

        // Everything, not just the live ones: the expired rows are the
        // archive's history, and a notice whose expiry Oracle later pushes out
        // would never come back if we only ever asked for what is live now.
        $rows = $this->api->announcements(false, $settings);

        if (SyncGuards::refusesPayload(count($rows), $settings->last_announcements_count, self::FLOOR)) {
            throw new RuntimeException(SyncGuards::payloadReason(
                'announcements', count($rows), $settings->last_announcements_count, self::FLOOR
            ));
        }

        $run = fn () => Auditor::withoutAuditing(fn () => $this->apply($rows));

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $counts = $run();
            } finally {
                DB::rollBack();
            }

            return $counts + ['dry_run' => true];
        }

        $counts = $run();

        if ($this->changedAnything($counts)) {
            AnnouncementCache::flush();
        }

        $this->log($counts, $userId);

        $settings->forceFill([
            'last_announcements_sync_at' => now(),
            'last_announcements_count' => count($rows),
        ])->save();

        return $counts + ['dry_run' => false];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{created:int,updated:int,unchanged:int,kept:int,removed:int,restored:int,rows:int}
     */
    private function apply(array $rows): array
    {
        $now = CarbonImmutable::now();
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0,
            'removed' => 0, 'restored' => 0, 'rows' => count($rows)];

        $held = Announcement::query()->fromOracle()->get()->keyBy('external_id');
        $seen = [];

        foreach ($rows as $row) {
            $mapped = AnnouncementMapper::map($row, $now);

            if ($mapped === null) {
                continue;
            }

            $seen[$mapped['external_id']] = true;
            $existing = $held->get($mapped['external_id']);

            if ($existing === null) {
                $this->create($mapped, $now);
                $counts['created']++;

                continue;
            }

            $this->update($existing, $mapped, $now, $counts);
        }

        foreach ($held as $externalId => $announcement) {
            if (isset($seen[$externalId]) || $announcement->removed_at !== null) {
                continue;
            }

            $this->withdraw($announcement, $now);
            $counts['removed']++;
        }

        return $counts;
    }

    /**
     * A new notice goes live as it stands: title and dates, no body, no
     * picture. Oracle has no audience or severity, so it is an ordinary
     * company-wide `info` notice, and an admin changing either keeps their
     * change — neither is ever written again.
     */
    private function create(array $mapped, CarbonImmutable $now): void
    {
        Announcement::create([
            'source' => Announcement::SOURCE_ORACLE,
            'external_id' => $mapped['external_id'],
            'title' => $mapped['title'],
            'body' => $mapped['body'],
            'external_image_name' => $mapped['external_image_name'],
            'published_at' => $mapped['published_at'],
            'expires_at' => $mapped['expires_at'],
            'severity' => 'info',
            'audience' => 'all',
            'pinned' => false,
            'is_published' => true,
            'created_by_name' => 'Oracle Employee Portal',
            'synced_at' => $now,
            'synced_fields' => AnnouncementMapper::snapshot($mapped),
        ]);
    }

    private function update(Announcement $announcement, array $mapped, CarbonImmutable $now, array &$counts): void
    {
        $snapshot = is_array($announcement->synced_fields) ? $announcement->synced_fields : [];

        // Read through the model's casts, so dates are Carbon on both sides
        // and the mapper's comparison sees like for like.
        $held = [];
        foreach (AnnouncementMapper::MANAGED as $column) {
            $held[$column] = $announcement->$column;
        }

        $writable = AnnouncementMapper::writableColumns($held, $snapshot);
        $incoming = AnnouncementMapper::snapshot($mapped);

        $changed = false;
        $kept = false;

        foreach (AnnouncementMapper::MANAGED as $column) {
            if (! in_array($column, $writable, true)) {
                // Somebody edited this here. It is theirs now, and the
                // snapshot keeps naming what the sync last wrote, so they can
                // hand it back by restoring Oracle's own text.
                $kept = true;

                continue;
            }

            if (AnnouncementMapper::scalarOf($held[$column]) !== $incoming[$column]) {
                $announcement->$column = $mapped[$column];
                $changed = true;
            }

            $snapshot[$column] = $incoming[$column];
        }

        // Oracle's own record of which image the notice is. Nothing here edits
        // it, so it is refreshed unconditionally.
        if ($announcement->external_image_name !== $mapped['external_image_name']) {
            $announcement->external_image_name = $mapped['external_image_name'];
            $changed = true;
        }

        if ($announcement->removed_at !== null) {
            $announcement->removed_at = null;
            $changed = true;
            $counts['restored']++;

            // Only put it back on the board if the sync is what took it off.
            if (($snapshot['unpublished_by_sync'] ?? false) === true) {
                $announcement->is_published = true;
                unset($snapshot['unpublished_by_sync']);
            }
        }

        $announcement->synced_at = $now;
        $announcement->synced_fields = $snapshot;
        $announcement->save();

        $changed ? $counts['updated']++ : $counts['unchanged']++;

        if ($kept) {
            $counts['kept']++;
        }
    }

    /**
     * Gone from Oracle: taken off the board but kept, with a note to itself so
     * that if Oracle lists it again the sync republishes only what the sync
     * unpublished — never something an admin chose to hide.
     */
    private function withdraw(Announcement $announcement, CarbonImmutable $now): void
    {
        $snapshot = is_array($announcement->synced_fields) ? $announcement->synced_fields : [];

        if ($announcement->is_published) {
            $announcement->is_published = false;
            $snapshot['unpublished_by_sync'] = true;
        }

        $announcement->removed_at = $now;
        $announcement->synced_at = $now;
        $announcement->synced_fields = $snapshot;
        $announcement->save();
    }

    private function changedAnything(array $counts): bool
    {
        return ($counts['created'] + $counts['updated'] + $counts['removed'] + $counts['restored']) > 0;
    }

    /**
     * One row per run. The model is auto-audited, so the writes above are
     * muted: 61 rows an hour would otherwise bury the edits people actually
     * make in the admin form, which stay audited as usual.
     */
    private function log(array $counts, ?int $userId): void
    {
        ActivityLog::create([
            'model_type' => Announcement::class,
            'model_id' => 0,
            'model_label' => 'Oracle Employee Portal',
            'action' => 'announcement_sync',
            'changes' => $counts,
            'user_id' => $userId,
        ]);
    }
}
