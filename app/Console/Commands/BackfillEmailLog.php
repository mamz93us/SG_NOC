<?php

namespace App\Console\Commands;

use App\Models\EmailMarketing\EmailEvent;
use App\Models\EmailMessage;
use App\Services\EmailLog\EmailMessageRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild the sent-mail log from the SES events already stored.
 *
 * email_events has been collecting every SES notification since the
 * configuration set was created — including transactional NOC alerts, which
 * nothing ever surfaced. This replays those rows through the same recorder the
 * live SNS ingest uses, so history and new mail are built identically.
 *
 * Safe to re-run: the recorder is idempotent (status only moves forward, and
 * open/click counts are recounted from email_events rather than incremented).
 */
class BackfillEmailLog extends Command
{
    protected $signature = 'email-log:backfill
                            {--chunk=500 : Events to read per batch}
                            {--fresh : Delete existing email_messages rows first}';

    protected $description = 'Rebuild email_messages from the SES events already in email_events';

    public function handle(EmailMessageRecorder $recorder): int
    {
        if ($this->option('fresh')) {
            $wiped = EmailMessage::query()->delete();
            $this->warn("Deleted {$wiped} existing email_messages rows.");
        }

        $total = EmailEvent::whereNotNull('ses_message_id')->count();
        if ($total === 0) {
            $this->info('No SES events to replay.');

            return self::SUCCESS;
        }

        $this->info("Replaying {$total} events…");
        $bar = $this->output->createProgressBar($total);

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        // Ordered by id so a message's events replay in the order they arrived,
        // which is what makes the "status only moves forward" rule meaningful.
        EmailEvent::whereNotNull('ses_message_id')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($events) use ($recorder, $bar, &$processed, &$skipped, &$failed) {
                foreach ($events as $event) {
                    $payload = $event->raw_payload;

                    if (! is_array($payload) || empty($payload['mail']['messageId'])) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }

                    try {
                        $recorder->record($payload, $event->email_campaign_send_id);
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('email-log:backfill failed on event '.$event->id.': '.$e->getMessage());
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $messages = EmailMessage::count();
        $earliest = EmailMessage::min('sent_at');

        $this->info("Replayed {$processed} events into {$messages} messages.");
        if ($skipped) {
            $this->warn("Skipped {$skipped} events with no usable payload.");
        }
        if ($failed) {
            $this->error("{$failed} events failed — see the log.");
        }
        $this->line('History now reaches back to: '.($earliest ?: 'n/a'));

        $this->table(
            ['Source', 'Messages'],
            EmailMessage::selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source')
                ->map(fn ($c, $source) => [$source, $c])->values()->all()
        );

        return self::SUCCESS;
    }
}
