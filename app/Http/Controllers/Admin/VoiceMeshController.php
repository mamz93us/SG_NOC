<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\NocEvent;
use App\Models\Setting;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use App\Models\VoiceMeshResult;
use App\Models\VoiceMeshRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Voice Mesh — the live board for synthetic branch-to-branch call testing.
 *
 * Where Tunnel Health answers "can a packet cross?", this answers "can a call
 * complete?" The prober is a systemd service on this host, not something the
 * app can trigger, so there is deliberately no "Check now" button here — see
 * index()'s next-run indicator instead.
 *
 * Viewing needs view-voice-mesh; editing needs manage-voice-mesh (a higher bar
 * than the network permissions, because node editing sets SIP passwords).
 */
class VoiceMeshController extends Controller
{
    /** How many recent checks a pair's drill-down strip shows. */
    private const STRIP_LENGTH = 120;

    public function index()
    {
        return view('admin.network.voice-mesh', array_merge($this->snapshot(), [
            'nodeRows' => VoiceMeshNode::with('branch')->ordered()->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'secretIsSet' => $this->secretIsSet(),
        ]));
    }

    /** JSON snapshot for the page's poll. Read-only, cheap. */
    public function data()
    {
        return response()->json($this->snapshot() + ['generated_at' => now()->toIso8601String()]);
    }

    /**
     * The whole board in one array: the nodes, every cell of the matrix, the
     * row/column totals and the last run.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $nodes = VoiceMeshNode::ordered()->get();
        $pairs = VoiceMeshPair::with(['caller', 'dest'])->get();
        $rates = $this->successRates($pairs->pluck('id')->all());

        $cells = [];
        $rowTotals = [];
        $colTotals = [];

        foreach ($pairs as $pair) {
            if (! $pair->caller || ! $pair->dest) {
                continue;
            }

            $stale = $pair->isStale();
            $status = $stale && $pair->status !== VoiceMeshPair::STATUS_UNKNOWN ? 'stale' : $pair->status;

            $cells["{$pair->caller_node_id}:{$pair->dest_node_id}"] = [
                'pair_id' => $pair->id,
                'status' => $status,
                'reason' => $pair->last_reason,
                'rx_pkt' => $pair->last_rx_pkt,
                'duration_sec' => $pair->last_duration_sec !== null ? (float) $pair->last_duration_sec : null,
                'reference_sec' => $pair->last_reference_sec !== null ? (float) $pair->last_reference_sec : null,
                'drift_pct' => $pair->driftPct() !== null ? round($pair->driftPct(), 1) : null,
                'checked' => $pair->last_checked_at?->diffForHumans(short: true),
                'consecutive_failures' => $pair->consecutive_failures,
                'success_24h' => $rates[$pair->id] ?? null,
                'url' => route('admin.network.voice-mesh.pair', $pair),
                'title' => $this->cellTitle($pair, $status),
            ];

            $ok = $pair->status === VoiceMeshPair::STATUS_OK;
            $this->tally($rowTotals, $pair->caller_node_id, $ok);
            $this->tally($colTotals, $pair->dest_node_id, $ok);
        }

        $lastRun = VoiceMeshRun::recent()->first();

        return [
            'nodes' => $nodes->map(fn (VoiceMeshNode $n) => [
                'id' => $n->id,
                'code' => $n->code,
                'name' => $n->name,
                'ivr_ext' => $n->ivr_ext,
                'is_active' => $n->is_active,
                'state' => $n->state,
                'state_label' => $n->stateLabel(),
                'badge' => $n->stateBadgeClass(),
            ])->values(),
            'cells' => $cells,
            'row_totals' => $rowTotals,
            'col_totals' => $colTotals,
            'summary' => [
                'ok' => $pairs->where('status', VoiceMeshPair::STATUS_OK)->count(),
                'fail' => $pairs->where('status', VoiceMeshPair::STATUS_FAIL)->count(),
                'unknown' => $pairs->where('status', VoiceMeshPair::STATUS_UNKNOWN)->count(),
                'pairs' => $pairs->count(),
                'nodes' => $nodes->where('is_active', true)->count(),
            ],
            'last_run' => $lastRun ? [
                'id' => $lastRun->id,
                'age' => $lastRun->received_at->diffForHumans(short: true),
                'ok' => $lastRun->ok,
                'pairs_ok' => $lastRun->pairs_ok,
                'pairs_total' => $lastRun->pairs_total,
                'next_due' => $lastRun->received_at
                    ->copy()
                    ->addMinutes((int) config('voice_mesh.interval_minutes'))
                    ->diffForHumans(short: true),
                'unknown_nodes' => $lastRun->unknown_nodes,
            ] : null,
        ];
    }

    private function tally(array &$totals, int $nodeId, bool $ok): void
    {
        $totals[$nodeId] ??= ['ok' => 0, 'total' => 0];
        $totals[$nodeId]['total']++;
        $totals[$nodeId]['ok'] += $ok ? 1 : 0;
    }

    private function cellTitle(VoiceMeshPair $pair, string $status): string
    {
        $parts = [$pair->label(), strtoupper($status)];

        if ($pair->last_duration_sec !== null) {
            $parts[] = sprintf('%.2fs vs %.2fs', $pair->last_duration_sec, $pair->last_reference_sec);
        }
        if ($pair->last_rx_pkt !== null) {
            $parts[] = "{$pair->last_rx_pkt} pkt";
        }
        if ($pair->last_checked_at) {
            $parts[] = $pair->last_checked_at->diffForHumans();
        }
        if ($pair->last_reason) {
            $parts[] = $pair->last_reason;
        }

        return implode(' · ', $parts);
    }

    /**
     * 24-hour success rate for every pair in ONE grouped query, bucketed in PHP.
     * No window functions, so it behaves the same on the SQLite the tests use as
     * on production MySQL — the same approach TunnelHealthController::uptime()
     * takes.
     *
     * @param  array<int, int>  $pairIds
     * @return array<int, float>
     */
    private function successRates(array $pairIds): array
    {
        if ($pairIds === []) {
            return [];
        }

        $rows = VoiceMeshResult::query()
            ->where('checked_at', '>=', now()->subDay())
            ->select('caller_node_id', 'dest_node_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) as ok_count')
            ->groupBy('caller_node_id', 'dest_node_id')
            ->get();

        $byNodes = $rows->keyBy(fn ($r) => "{$r->caller_node_id}:{$r->dest_node_id}");

        return VoiceMeshPair::whereIn('id', $pairIds)
            ->get(['id', 'caller_node_id', 'dest_node_id'])
            ->mapWithKeys(function (VoiceMeshPair $p) use ($byNodes) {
                $row = $byNodes->get("{$p->caller_node_id}:{$p->dest_node_id}");

                return [$p->id => $row && $row->total
                    ? round($row->ok_count / $row->total * 100, 1)
                    : null];
            })
            ->all();
    }

    // ─────────────────────────────────────────────────────────────
    // Drill-down and history
    // ─────────────────────────────────────────────────────────────

    public function pair(VoiceMeshPair $pair)
    {
        $pair->load(['caller', 'dest']);

        $checks = VoiceMeshResult::where('caller_node_id', $pair->caller_node_id)
            ->where('dest_node_id', $pair->dest_node_id)
            ->orderByDesc('checked_at')
            ->limit(self::STRIP_LENGTH)
            ->get()
            ->reverse()
            ->values();

        // "no RTP media received" and "call never reached CONFIRMED" are
        // completely different faults — which one dominates is the diagnosis.
        $reasons = VoiceMeshResult::where('caller_node_id', $pair->caller_node_id)
            ->where('dest_node_id', $pair->dest_node_id)
            ->where('ok', false)
            ->where('checked_at', '>=', now()->subDays(30))
            ->select('reason', DB::raw('COUNT(*) as cnt'))
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        $events = NocEvent::whereIn('source_type', [
            'voice_mesh_pair_failed', 'voice_mesh_caller_down', 'voice_mesh_dest_down',
        ])
            ->whereIn('source_id', [$pair->id, $pair->caller_node_id, $pair->dest_node_id])
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('id')
            ->get();

        return view('admin.network.voice-mesh-pair', [
            'pair' => $pair,
            'checks' => $checks,
            'reasons' => $reasons,
            'events' => $events,
            'rates' => [
                '24 hours' => $this->rateOver($pair, 1),
                '7 days' => $this->rateOver($pair, 7),
                '30 days' => $this->rateOver($pair, 30),
            ],
        ]);
    }

    private function rateOver(VoiceMeshPair $pair, int $days): ?float
    {
        $row = VoiceMeshResult::where('caller_node_id', $pair->caller_node_id)
            ->where('dest_node_id', $pair->dest_node_id)
            ->where('checked_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) as ok_count')
            ->first();

        return $row && $row->total ? round($row->ok_count / $row->total * 100, 1) : null;
    }

    public function runs()
    {
        return view('admin.network.voice-mesh-runs', [
            'runs' => VoiceMeshRun::recent()->paginate(50),
        ]);
    }

    public function run(VoiceMeshRun $run)
    {
        return view('admin.network.voice-mesh-run', [
            'run' => $run,
            'results' => VoiceMeshResult::where('voice_mesh_run_id', $run->id)
                ->orderBy('caller_code')->orderBy('dest_code')->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Node CRUD
    // ─────────────────────────────────────────────────────────────

    public function storeNode(Request $request)
    {
        $data = $this->validateNode($request);
        $data['sip_pass'] = $data['sip_pass'] ?? '';

        $node = VoiceMeshNode::create($data);

        // redactedForLog(), not the model: the audit trail must not become an
        // easier place to read SIP passwords than the encrypted column.
        ActivityLog::log("Added voice mesh node {$node->code} ({$node->sip_server})", $node, $node->redactedForLog());

        return back()->with('success', "Added {$node->code}. It will be dialled on the next sweep.");
    }

    public function updateNode(Request $request, VoiceMeshNode $node)
    {
        $data = $this->validateNode($request, $node);

        // An empty password field means "leave unchanged" — the plaintext is
        // never round-tripped into the DOM, so there is nothing to submit back.
        if (blank($data['sip_pass'] ?? null)) {
            unset($data['sip_pass']);
        }

        // Re-baseline when the thing being tested changes. Old state describes a
        // different configuration, and a stale green must not survive a
        // re-point — the same guard TunnelHealthController applies to targets.
        $retargeted = collect(['sip_server', 'sip_port', 'sip_user', 'ivr_ext'])
            ->contains(fn (string $field) => array_key_exists($field, $data) && $node->{$field} != $data[$field])
            || array_key_exists('sip_pass', $data);

        $node->update($data);

        if ($retargeted) {
            $this->rebaseline($node);
        }

        ActivityLog::log(
            "Updated voice mesh node {$node->code}".($retargeted ? ' (re-pointed — state reset to unknown)' : ''),
            $node,
            $node->redactedForLog()
        );

        return back()->with('success', "Updated {$node->code}."
            .($retargeted ? ' Its legs were reset to unknown until the next sweep.' : ''));
    }

    /**
     * Drop everything we thought we knew about this node's legs, and close any
     * events for it — an operator who has just fixed the credentials should not
     * be left staring at a critical that describes the old configuration.
     */
    private function rebaseline(VoiceMeshNode $node): void
    {
        VoiceMeshPair::where('caller_node_id', $node->id)
            ->orWhere('dest_node_id', $node->id)
            ->update([
                'status' => VoiceMeshPair::STATUS_UNKNOWN,
                'consecutive_failures' => 0,
                'last_checked_at' => null,
                'last_reason' => null,
            ]);

        $node->forceFill([
            'state' => VoiceMeshNode::STATE_UNKNOWN,
            'consecutive_failures' => 0,
            'last_result_at' => null,
        ])->saveQuietly();

        NocEvent::whereIn('source_type', ['voice_mesh_caller_down', 'voice_mesh_dest_down'])
            ->where('source_id', $node->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function destroyNode(VoiceMeshNode $node)
    {
        $code = $node->code;
        // Pairs cascade; voice_mesh_results deliberately do not (no FK), so the
        // forensic history of this branch survives its removal.
        $node->delete();

        ActivityLog::log("Removed voice mesh node {$code}");

        return back()->with('success', "Removed {$code}. Its call history was kept.");
    }

    /** @return array<string, mixed> */
    private function validateNode(Request $request, ?VoiceMeshNode $node = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:16|regex:/^[A-Za-z0-9_-]+$/|unique:voice_mesh_nodes,code'
                .($node ? ",{$node->id}" : ''),
            'name' => 'required|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'ivr_ext' => 'required|string|max:16',
            'sip_server' => 'required|ip',
            'sip_port' => 'nullable|integer|min:1|max:65535',
            'sip_user' => 'required|string|max:64',
            'sip_pass' => ($node ? 'nullable' : 'required').'|string|max:191',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Ingest secret
    // ─────────────────────────────────────────────────────────────

    /**
     * Mint a new ingest secret and show it once. The prober's config.conf on
     * this host has to be updated to match, or the next sweep 401s — which the
     * stale check will then report.
     */
    public function rotateSecret()
    {
        $secret = bin2hex(random_bytes(24));

        $settings = Setting::get();
        $settings->voice_mesh_secret = $secret;
        $settings->save();

        ActivityLog::log('Rotated the voice mesh ingest secret');

        return back()
            ->with('voice_mesh_secret', $secret)
            ->with('success', 'New ingest secret generated — copy it now, it is not shown again.');
    }

    private function secretIsSet(): bool
    {
        try {
            return (string) (Setting::get()->voice_mesh_secret ?? '') !== ''
                || (string) config('services.voice_mesh.secret', '') !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
