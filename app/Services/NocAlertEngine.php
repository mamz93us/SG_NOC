<?php

namespace App\Services;

use App\Models\NocEvent;
use App\Models\NetworkSwitch;
use App\Models\UcmServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NocAlertEngine
{
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
                $this->createOrUpdateEvent(
                    'network',
                    'switch',
                    $sw->serial,
                    'critical',
                    "Switch Offline: {$sw->name}",
                    "Switch {$sw->name} ({$sw->serial}) is reporting offline status."
                );
            } elseif ($isStale) {
                $this->createOrUpdateEvent(
                    'network',
                    'switch',
                    $sw->serial,
                    'warning',
                    "Switch Not Syncing: {$sw->name}",
                    "Switch {$sw->name} ({$sw->serial}) has not been synced in over 30 minutes."
                );
            } else {
                // Auto-resolve open events for this switch
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
                $resp = Http::timeout(5)->get(rtrim($server->url, '/') . '/api');
                $reachable = $resp->successful() || $resp->status() === 401;
            } catch (\Throwable $e) {
                $reachable = false;
            }

            if (!$reachable) {
                $this->createOrUpdateEvent(
                    'voip',
                    'ucm_server',
                    (string) $server->id,
                    'critical',
                    "UCM Unreachable: {$server->name}",
                    "UCM server {$server->name} ({$server->url}) is not responding."
                );
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
    // Auto-resolve stale events (resolved conditions)
    // ─────────────────────────────────────────────────────────────

    public function resolveStaleEvents(): void
    {
        // Auto-resolve events older than 24h that haven't been updated
        NocEvent::where('status', 'open')
            ->where('last_seen', '<', now()->subHours(24))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
