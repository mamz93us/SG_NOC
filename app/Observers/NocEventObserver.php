<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Incident;
use App\Models\NocEvent;

class NocEventObserver
{
    /**
     * Auto-escalate critical NocEvents into Incidents so the incident queue
     * captures them even when nobody clicks "Create Incident" from the NOC UI.
     * Uses firstOrCreate on noc_event_id so re-firing the observer (retries,
     * event updates) cannot produce duplicate incidents.
     */
    public function created(NocEvent $event): void
    {
        if ($event->severity !== 'critical') {
            return;
        }

        try {
            $incident = Incident::firstOrCreate(
                ['noc_event_id' => $event->id],
                [
                    'title'       => $event->title ?: "NOC Event #{$event->id}",
                    'description' => $event->message ?? 'Auto-escalated from NOC event.',
                    'severity'    => 'critical',
                    'status'      => 'open',
                    'created_by'  => null,
                ]
            );

            if ($incident->wasRecentlyCreated) {
                ActivityLog::create([
                    'model_type' => Incident::class,
                    'model_id'   => $incident->id,
                    'action'     => 'auto_escalated_from_noc',
                    'changes'    => [
                        'noc_event_id' => $event->id,
                        'module'       => $event->module,
                        'severity'     => $event->severity,
                    ],
                    'user_id' => null,
                ]);
            }
        } catch (\Throwable) {
            // Escalation must not block event creation.
        }
    }

    /**
     * When the originating NocEvent is resolved (e.g. VpnMonitorService flips
     * the tunnel back to up), close the auto-created incident in lockstep so
     * the incident queue doesn't stay red after the underlying condition clears.
     */
    public function updated(NocEvent $event): void
    {
        if (! $event->wasChanged('status') || $event->status !== 'resolved') {
            return;
        }

        try {
            Incident::where('noc_event_id', $event->id)
                ->whereIn('status', ['open', 'investigating'])
                ->get()
                ->each(function (Incident $incident) use ($event) {
                    $incident->update([
                        'status'           => 'resolved',
                        'resolved_at'      => now(),
                        'resolution_notes' => trim(($incident->resolution_notes ? $incident->resolution_notes . "\n" : '')
                            . 'Auto-resolved: underlying NOC event cleared.'),
                    ]);

                    ActivityLog::create([
                        'model_type' => Incident::class,
                        'model_id'   => $incident->id,
                        'action'     => 'auto_resolved_by_noc',
                        'changes'    => ['noc_event_id' => $event->id],
                        'user_id'    => null,
                    ]);
                });
        } catch (\Throwable) {
            // Don't let incident cleanup block the NocEvent resolution.
        }
    }
}
