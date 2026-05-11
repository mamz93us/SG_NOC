<?php

namespace App\Console\Commands;

use App\Models\CupsPrinter;
use App\Models\NocEvent;
use App\Services\CupsService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CupsRefreshStatus extends Command
{
    protected $signature = 'cups:refresh-status';

    protected $description = 'Refresh status of all active CUPS printers';

    public function handle(NotificationService $notifications): int
    {
        $cups     = new CupsService();
        $printers = CupsPrinter::active()->get();

        if ($printers->isEmpty()) {
            $this->info('No active CUPS printers found.');
            return self::SUCCESS;
        }

        $this->info("Refreshing status for {$printers->count()} printer(s)...");

        $rows = [];

        foreach ($printers as $printer) {
            $previous = $printer->last_status;
            $status   = $cups->getStatus($printer->queue_name);

            $printer->update([
                'status'          => $status,
                'last_status'     => $status,
                'last_checked_at' => now(),
            ]);

            $this->handleStateTransition($printer, $previous, $status, $notifications);

            $rows[] = [$printer->queue_name, $printer->ip_address, $status];
        }

        $this->table(['Queue', 'IP', 'Status'], $rows);
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Open a NocEvent + dispatch a one-shot notification when a printer
     * transitions into a non-online state, and resolve the matching open
     * event when it recovers. Mirrors NocAlertEngine's offline/online flow.
     */
    private function handleStateTransition(
        CupsPrinter $printer,
        ?string $previous,
        string $current,
        NotificationService $notifications
    ): void {
        $offlineStates = ['offline', 'error', 'unknown'];
        $wasOffline    = $previous !== null && in_array($previous, $offlineStates, true);
        $isOffline     = in_array($current, $offlineStates, true);

        // First poll for this printer — record baseline only, never alert.
        if ($previous === null) {
            return;
        }

        if ($isOffline && ! $wasOffline) {
            $title   = "CUPS Printer Offline: {$printer->name}";
            $message = "CUPS printer {$printer->name} (queue {$printer->queue_name}) is reporting status '{$current}'.";

            $event = $this->createOrUpdateEvent($printer, $current, $title, $message);

            // Only notify on freshly-opened events — protects against duplicate
            // emails if the printer has been bouncing in/out of offline state.
            if ($event->wasRecentlyCreated) {
                $notifications->notifyViaRules(
                    type:     'cups_printer_offline',
                    title:    $title,
                    message:  $message,
                    link:     null,
                    severity: 'critical'
                );
            }
        } elseif (! $isOffline && $wasOffline) {
            NocEvent::where('module', 'printers')
                ->where('entity_type', 'cups_printer')
                ->where('entity_id', (string) $printer->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    private function createOrUpdateEvent(
        CupsPrinter $printer,
        string $statusValue,
        string $title,
        string $message
    ): NocEvent {
        $existing = NocEvent::where('module', 'printers')
            ->where('entity_type', 'cups_printer')
            ->where('entity_id', (string) $printer->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        if ($existing) {
            $existing->update([
                'last_seen' => now(),
                'message'   => $message,
            ]);
            return $existing;
        }

        return NocEvent::create([
            'module'      => 'printers',
            'entity_type' => 'cups_printer',
            'entity_id'   => (string) $printer->id,
            'source_type' => 'cups_printer',
            'source_id'   => $printer->id,
            'severity'    => 'critical',
            'title'       => $title,
            'message'     => $message,
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
        ]);
    }
}
