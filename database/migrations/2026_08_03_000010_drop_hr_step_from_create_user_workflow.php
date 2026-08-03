<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the HR approval step from the onboarding (create_user) workflow.
 *
 * The chain was ['hr', 'it_manager'] from when IT raised onboarding requests on
 * HR's behalf. Now that HR submits them directly from the HR portal, the HR step
 * is HR approving their own request — pure friction. (It was also un-actionable
 * by actual HR-role users: WorkflowRequest::isAwaitingMyApproval() maps an 'hr'
 * step to admin roles only.)
 *
 * Two parts:
 *   1. The template chain, so new requests only need IT approval.
 *   2. In-flight requests that are still sitting on the HR step, so nobody has
 *      to click through a step that no longer exists. Deliberately limited to
 *      workflows where NOTHING has been actioned yet — anything with an approval
 *      already recorded is history and is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setChain(['it_manager']);
        $this->dropPendingHrSteps();
    }

    /**
     * Restores the original two-step chain. In-flight steps are not recreated —
     * re-adding a step to a request already part-way through its chain would be
     * worse than leaving it.
     */
    public function down(): void
    {
        $this->setChain(['hr', 'it_manager']);
    }

    private function setChain(array $chain): void
    {
        $template = DB::table('workflow_templates')
            ->where('type_slug', 'create_user')
            ->first();

        if (! $template) {
            return;
        }

        DB::table('workflow_templates')
            ->where('id', $template->id)
            ->update([
                'approval_chain' => json_encode($chain),
                'updated_at' => now(),
            ]);
    }

    /**
     * Remove the pending HR step from create_user requests that have not been
     * actioned at all, then renumber what is left so step_number stays 1..N and
     * current_step still points at the first pending step.
     */
    private function dropPendingHrSteps(): void
    {
        $workflowIds = DB::table('workflow_requests')
            ->where('type', 'create_user')
            ->whereIn('status', ['draft', 'pending'])
            ->pluck('id');

        foreach ($workflowIds as $workflowId) {
            $steps = DB::table('workflow_steps')
                ->where('workflow_id', $workflowId)
                ->orderBy('step_number')
                ->get();

            // Skip anything already part-way through — only untouched chains.
            if ($steps->isEmpty() || $steps->contains(fn ($s) => $s->status !== 'pending')) {
                continue;
            }

            $hrSteps = $steps->where('approver_role', 'hr');
            if ($hrSteps->isEmpty()) {
                continue;
            }

            // Never leave a request with no approval step at all.
            $remaining = $steps->where('approver_role', '!=', 'hr')->values();
            if ($remaining->isEmpty()) {
                continue;
            }

            DB::table('workflow_steps')
                ->whereIn('id', $hrSteps->pluck('id'))
                ->delete();

            foreach ($remaining as $i => $step) {
                DB::table('workflow_steps')
                    ->where('id', $step->id)
                    ->update(['step_number' => $i + 1]);
            }

            DB::table('workflow_requests')
                ->where('id', $workflowId)
                ->update([
                    'current_step' => 1,
                    'total_steps' => $remaining->count(),
                    'updated_at' => now(),
                ]);

            DB::table('workflow_logs')->insert([
                'workflow_id' => $workflowId,
                'level' => 'info',
                'message' => 'HR approval step removed — onboarding requests are raised by HR, so only IT approval is required.',
                'created_at' => now(),
            ]);
        }
    }
};
