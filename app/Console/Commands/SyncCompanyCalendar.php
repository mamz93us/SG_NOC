<?php

namespace App\Console\Commands;

use App\Models\CompanyEvent;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refills `company_events` from the shared Microsoft 365 calendar.
 *
 * The home portal never calls Graph on request — the whole company opens that
 * page within minutes of 9am, and a Graph round trip on each would be both slow
 * and a good way to get throttled. This command is the only thing that talks to
 * Microsoft.
 */
class SyncCompanyCalendar extends Command
{
    protected $signature = 'company-calendar:sync
                            {--days=90 : How far ahead to pull}
                            {--back=1 : How many days back to include, so today stays visible}';

    protected $description = 'Sync the shared company calendar from Microsoft Graph into company_events';

    public function handle(GraphService $graph): int
    {
        $settings = Setting::get();

        if (! $settings->company_calendar_enabled) {
            $this->comment('Company calendar sync is disabled in Settings — nothing to do.');

            return self::SUCCESS;
        }

        $mailbox = trim((string) $settings->company_calendar_mailbox);

        if ($mailbox === '') {
            $this->error('No calendar mailbox configured (Settings → Company Calendar).');

            return self::FAILURE;
        }

        $start = CarbonImmutable::now()->subDays(max(0, (int) $this->option('back')))->startOfDay();
        $end = CarbonImmutable::now()->addDays(max(1, (int) $this->option('days')))->endOfDay();

        $this->info("Pulling {$mailbox} from {$start->toDateString()} to {$end->toDateString()}…");

        try {
            $events = $graph->getCalendarView($mailbox, $start, $end, config('app.timezone', 'Africa/Cairo'));
        } catch (\Throwable $e) {
            // A Calendars.Read consent that was never granted shows up here as a
            // 403, and it is by far the likeliest cause — say so rather than
            // leaving a bare Graph error in the log.
            $this->error('Graph call failed: '.$e->getMessage());
            Log::error('[company-calendar:sync] '.$e->getMessage(), ['mailbox' => $mailbox]);

            if (str_contains($e->getMessage(), '403')) {
                $this->warn('A 403 here usually means the Calendars.Read APPLICATION permission is missing or not admin-consented on the app registration.');
            }

            return self::FAILURE;
        }

        $syncedAt = now();
        $seen = [];

        foreach ($events as $event) {
            $externalId = $event['id'] ?? null;

            if (! $externalId) {
                continue;
            }

            $startAt = $this->parse($event['start'] ?? null);

            if (! $startAt) {
                // An event with no usable start cannot be placed on a timeline.
                continue;
            }

            CompanyEvent::updateOrCreate(
                ['external_id' => $externalId],
                [
                    'subject' => mb_substr((string) ($event['subject'] ?? 'Untitled'), 0, 300),
                    'body_preview' => $event['bodyPreview'] ?? null,
                    'location' => mb_substr((string) ($event['location']['displayName'] ?? ''), 0, 300) ?: null,
                    'starts_at' => $startAt,
                    'ends_at' => $this->parse($event['end'] ?? null),
                    'is_all_day' => (bool) ($event['isAllDay'] ?? false),
                    'organizer' => $event['organizer']['emailAddress']['name'] ?? null,
                    'web_link' => mb_substr((string) ($event['webLink'] ?? ''), 0, 1000) ?: null,
                    'synced_at' => $syncedAt,
                ]
            );

            $seen[] = $externalId;
        }

        // Anything in the window we did NOT see this run has been cancelled or
        // moved out — drop it, or a cancelled company holiday sits on the
        // portal forever. Scoped to the window so past events outside it stay.
        $removed = CompanyEvent::where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $end)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('external_id', $seen))
            ->delete();

        $settings->company_calendar_last_sync_at = $syncedAt;
        $settings->save();

        Cache::forget('home.company_events');

        $this->info('Synced '.count($seen).' event(s); removed '.$removed.' stale.');

        return self::SUCCESS;
    }

    /**
     * Graph returns {dateTime, timeZone}. With the Prefer header set, dateTime
     * is already in the requested zone, so parse it there and store UTC.
     */
    private function parse(?array $slot): ?CarbonImmutable
    {
        if (! $slot || empty($slot['dateTime'])) {
            return null;
        }

        try {
            $zone = $slot['timeZone'] ?? config('app.timezone', 'UTC');

            return CarbonImmutable::parse($slot['dateTime'], $zone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
