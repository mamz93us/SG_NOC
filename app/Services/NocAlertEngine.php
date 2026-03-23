<?php

namespace App\Services;

use App\Models\NocEvent;
use App\Models\NetworkSwitch;
use App\Models\UcmServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NocAlertEngine
{
    public function __construct(private NotificationService $notifications) {}

    public function detectAll(): void
    {
        $this->detectNetworkIssues();
        $this->detectVoipIssues();
        $this->resolveStaleEvents();
    }

    // ─────────────────────────────────────────────────────────────
    // Network: offline / stale switches
    // ─────────────────────────────────────────────────────────────

    public function detectNetworkIssues(): void
    {
        $staleThreshold = now()->subMinutes(30);
        $switches = NetworkSwitch::all();

        foreach ($switches as $sw) {
            $isOffline = !$sw->isOnline();
            $isStale   = $sw->updated_at && $sw->updated_at->isBefore($staleThreshold);

            if ($isOffline) {
                $event = $this->createOrUpdateEvent(
                    'network', 'switch', $sw->serial, 'critical',
                    "Switch Offline: {$sw->name}",
                    "Switch {$sw->name} ({$sw->serial}) is reporting offline status."
                );
                // Only notify on new event creation (not updates)
                if ($event->wasRecentlyCreated) {
                    $this->notifications->notifyAdmins(
                        'noc_alert',
                        "Switch Offline: {$sw->name}",
                        "Switch {$sw->name} ({$sw->serial}) has gone offline.",
                        null,
                        'critical'
                    );
                }
            } elseif ($isStale) {
                $event = $this->createOrUpdateEvent(
                    'network', 'switch', $sw->serial, 'warning',
                    "Switch Not Syncing: {$sw->name}",
                    "Switch {$sw->name} ({$sw->serial}) has not been synced in over 30 minutes."
                );
                if ($event->wasRecentlyCreated) {
                    $this->notifications->notifyAdmins(
                        'noc_alert',
                        "Switch Not Syncing: {$sw->name}",
                        "Switch {$sw->name} ({$sw->serial}) has not synced for 30+ minutes.",
                        null,
                        'warning'
                    );
                }
            } else {
                NocEvent::where('module', 'network')
                    ->where('entity_type', 'switch')
                    ->where('entity_id', $sw->serial)
                    ->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // VoIP: UCM unreachable
    // ─────────────────────────────────────────────────────────────

    public function detectVoipIssues(): void
    {
        $servers = UcmServer::where('is_active', true)->get();

        foreach ($servers as $server) {
            try {
                $resp      = Http::timeout(5)->get(rtrim($server->url, '/') . '/api');
                $reachable = $resp->successful() || $resp->status() === 401;
            } catch (\Throwable $e) {
                $reachable = false;
            }

            if (!$reachable) {
                $event = $this->createOrUpdateEvent(
                    'voip', 'ucm_server', (string) $server->id, 'critical',
                    "UCM Unreachable: {$server->name}",
                    "UCM server {$server->name} ({$server->url}) is not responding."
                );
                if ($event->wasRecentlyCreated) {
                    $this->notifications->notifyAdmins(
                        'noc_alert',
                        "UCM Unreachable: {$server->name}",
                        "UCM server {$server->name} at {$server->url} is not responding.",
                        null,
                        'critical'
                    );
                }
            } else {
                NocEvent::where('module', 'voip')
                    ->where('entity_type', 'ucm_server')
                    ->where('entity_id', (string) $server->id)
                    ->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Create or update event (idempotent)
    // ─────────────────────────────────────────────────────────────

    public function createOrUpdateEvent(
        string $module,
        string $entityType,
        ?string $entityId,
        string $severity,
        string $title,
        string $message
    ): NocEvent {
        $existing = NocEvent::where('module', $module)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        if ($existing) {
            $existing->update([
                'last_seen' => now(),
                'severity'  => $severity,
                'message'   => $message,
            ]);
            return $existing;
        }

        return NocEvent::create([
            'module'      => $module,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'severity'    => $severity,
            'title'       => $title,
            'message'     => $message,
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Auto-resolve stale events
    // ─────────────────────────────────────────────────────────────

    public function resolveStaleEvents(): void
    {
        NocEvent::where('status', 'open')
            ->where('last_seen', '<', now()->subHours(24))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
