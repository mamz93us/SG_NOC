<?php

namespace App\Console\Commands;

use App\Models\BranchAgent;
use App\Models\NocEvent;
use Illuminate\Console\Command;

/**
 * Marks branch agents stale/down when their heartbeat goes quiet, and
 * opens/resolves a NocEvent accordingly. The agent's own computeStatus() owns
 * the thresholds (config/branch_agents.php).
 */
class CheckStaleBranchAgents extends Command
{
    protected $signature = 'branch-agents:check-stale';

    protected $description = 'Flag branch agents whose heartbeat has gone stale/down and raise NocEvents';

    public function handle(): int
    {
        foreach (BranchAgent::ready()->get() as $agent) {
            $new = $agent->computeStatus();
            if ($new === $agent->status) {
                continue;
            }

            $agent->update(['status' => $new]);

            if ($new === 'down') {
                $this->openEvent($agent);
            } elseif ($new === 'healthy') {
                $this->resolveEvents($agent);
            }
        }

        return self::SUCCESS;
    }

    private function openEvent(BranchAgent $agent): void
    {
        $last = $agent->last_heartbeat_at?->diffForHumans() ?? 'never';

        NocEvent::updateOrCreate(
            [
                'module' => 'branch_agent',
                'entity_type' => 'branch_agent_heartbeat',
                'entity_id' => (string) $agent->id,
                'status' => 'open',
            ],
            [
                'source_type' => 'branch_agent',
                'source_id' => $agent->id,
                'severity' => 'warning',
                'title' => "Branch agent {$agent->code} is down",
                'message' => "No heartbeat from {$agent->code} ({$agent->name}); last seen {$last}.",
                'first_seen' => now(),
                'last_seen' => now(),
            ],
        );
    }

    private function resolveEvents(BranchAgent $agent): void
    {
        NocEvent::where('module', 'branch_agent')
            ->where('entity_type', 'branch_agent_heartbeat')
            ->where('entity_id', (string) $agent->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
