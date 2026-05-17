<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\SendHrFeedbackEmailJob;
use App\Models\Employee;
use App\Models\OffboardingToken;
use App\Models\OffboardingWorkflow;
use App\Services\Workflow\OffboardingProcessor;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardingFormController extends Controller
{
    /**
     * GET /offboarding/respond?token=xxx
     * Show the multi-decision manager form.
     */
    public function show(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $tokenString = $request->query('token');
        $token = OffboardingToken::where('token', $tokenString)->first();

        if (! $token || ! $token->isValid()) {
            return view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        // Pull the employee's active assets so the retrieval-task list is real.
        $employee = $token->employee_id ? Employee::find($token->employee_id) : null;
        $assets = $employee
            ? $employee->activeAssets()->with('device')->get()
            : collect();

        // Active employees for the "transfer to" picker (exclude the leaving user).
        $activeEmployees = Employee::query()
            ->where('status', 'active')
            ->when($employee, fn ($q) => $q->where('id', '!=', $employee->id))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('public.offboarding_form', [
            'token' => $token,
            'payload' => $token->payload ?? [],
            'assets' => $assets,
            'activeEmployees' => $activeEmployees,
        ]);
    }

    /**
     * POST /offboarding/respond
     * Manager submits their decisions.
     */
    public function submit(
        Request $request,
        WorkflowEngine $engine,
        OffboardingProcessor $processor,
    ): View {
        $data = $request->validate([
            'token' => 'required|string',
            'decision' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:2000',

            // Approve-only fields (validated conditionally below).
            'email_action' => 'nullable|in:delete,forward',
            'forward_emails' => 'nullable|string|max:1000',
            'forward_duration_days' => 'nullable|integer|min:1|max:90',
            'laptop_action' => 'nullable|in:backup,delete',
            'asset_action' => 'nullable|in:transfer,return_to_it',
            'asset_target_employee_id' => 'nullable|integer|exists:employees,id',
            'retrieve' => 'nullable|array',
        ]);

        $token = OffboardingToken::where('token', $data['token'])->first();
        if (! $token) {
            return view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        // Cross-field validation for approve path.
        if ($data['decision'] === 'approved') {
            $request->validate([
                'email_action' => 'required|in:delete,forward',
                'laptop_action' => 'required|in:backup,delete',
                'asset_action' => 'required|in:transfer,return_to_it',
            ]);
            if ($data['email_action'] === 'forward') {
                $request->validate([
                    'forward_emails' => 'required|string|max:1000',
                    'forward_duration_days' => 'required|integer|min:1|max:90',
                ]);
            }
            if ($data['asset_action'] === 'transfer') {
                $request->validate([
                    'asset_target_employee_id' => 'required|integer|exists:employees,id',
                ]);
            }
        }

        // ── Lock the token and persist decision (MVCC against double-submit) ──
        $validationFailed = false;
        \DB::transaction(function () use ($token, $data, &$validationFailed) {
            $locked = OffboardingToken::lockForUpdate()->find($token->id);
            if (! $locked || ! $locked->isValid()) {
                $validationFailed = true;

                return;
            }
            $locked->update([
                'manager_decision' => $data['decision'],
                'manager_notes' => $data['notes'] ?? null,
                'responded_at' => now(),
            ]);
            $locked->markUsed();
        });

        if ($validationFailed) {
            return view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        $workflow = $token->workflow;
        if (! $workflow) {
            return view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'The associated workflow could not be found.',
            ]);
        }

        $offboardingWorkflow = OffboardingWorkflow::firstOrCreate(
            ['workflow_id' => $workflow->id],
            [
                'employee_id' => $token->employee_id,
                'status' => 'manager_input_pending',
                'expected_last_day' => $token->payload['last_day'] ?? now()->toDateString(),
            ],
        );

        if ($data['decision'] === 'rejected') {
            $payload = $workflow->payload ?? [];
            $payload['manager_decision'] = 'rejected';
            $payload['manager_notes'] = $data['notes'] ?? null;
            $workflow->payload = $payload;
            $workflow->update(['status' => 'rejected']);

            $offboardingWorkflow->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);

            $engine->logEvent($workflow, 'info', 'Manager rejected offboarding request.');

            try {
                SendHrFeedbackEmailJob::dispatch($workflow->id, 'offboarding')->onQueue('emails');
            } catch (\Throwable) {
                // non-fatal
            }

            return view('public.offboarding_form_submitted', [
                'error' => false,
                'decision' => 'rejected',
                'payload' => [],
            ]);
        }

        // ── Approve path — persist the manager's decisions ──────────────────
        $forwardEmails = [];
        if ($data['email_action'] === 'forward' && ! empty($data['forward_emails'])) {
            $forwardEmails = collect(preg_split('/[,;\s]+/', $data['forward_emails']))
                ->map(fn ($e) => trim($e))
                ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
                ->values()
                ->all();
        }

        $forwardUntil = null;
        if ($data['email_action'] === 'forward' && ! empty($data['forward_duration_days'])) {
            $forwardUntil = now()->startOfDay()->addDays((int) $data['forward_duration_days'])->toDateString();
        }

        $retrievalChoices = [];
        foreach ((array) ($data['retrieve'] ?? []) as $assetId => $val) {
            $retrievalChoices[(int) $assetId] = (bool) $val;
        }

        $offboardingWorkflow->update([
            'status' => 'processing',
            'email_action' => $data['email_action'],
            'forward_emails' => $forwardEmails ?: null,
            'forward_until' => $forwardUntil,
            'laptop_action' => $data['laptop_action'],
            'asset_action' => $data['asset_action'],
            'asset_target_employee_id' => $data['asset_action'] === 'transfer'
                                            ? (int) $data['asset_target_employee_id']
                                            : null,
            'retrieval_choices' => $retrievalChoices,
        ]);

        // Mirror the decisions into the workflow payload so admin views can read them.
        $payload = $workflow->payload ?? [];
        $payload['manager_decision'] = 'approved';
        $payload['manager_notes'] = $data['notes'] ?? null;
        $payload['decisions'] = [
            'email_action' => $data['email_action'],
            'forward_emails' => $forwardEmails,
            'forward_until' => $forwardUntil,
            'laptop_action' => $data['laptop_action'],
            'asset_action' => $data['asset_action'],
            'asset_target_employee_id' => $data['asset_action'] === 'transfer'
                                            ? (int) $data['asset_target_employee_id']
                                            : null,
            'retrieval_choices' => $retrievalChoices,
        ];
        $workflow->payload = $payload;
        $workflow->update(['status' => 'executing']);

        $engine->logEvent($workflow, 'info', 'Manager approved offboarding. Starting deprovisioning.');

        // ── Hand off to the orchestrator (Phase C) ──────────────────────────
        try {
            $processor->beginProcessing($offboardingWorkflow);
            $engine->logEvent($workflow, 'success', 'Offboarding processing dispatched.');
        } catch (\Throwable $e) {
            $offboardingWorkflow->update(['status' => 'failed']);
            $workflow->update(['status' => 'failed']);
            $engine->logEvent($workflow, 'error', 'Offboarding processor failed: '.$e->getMessage());
        }

        try {
            SendHrFeedbackEmailJob::dispatch($workflow->id, 'offboarding')->onQueue('emails');
        } catch (\Throwable) {
            // non-fatal
        }

        return view('public.offboarding_form_submitted', [
            'error' => false,
            'decision' => 'approved',
            'payload' => collect($payload['decisions'] ?? [])->toArray(),
        ]);
    }
}
