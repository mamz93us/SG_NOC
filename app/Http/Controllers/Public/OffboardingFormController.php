<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\SendHrFeedbackEmailJob;
use App\Models\OffboardingToken;
use App\Services\Workflow\UserProvisioningService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardingFormController extends Controller
{
    /**
     * GET /offboarding/respond?token=xxx
     * Show the manager decision form.
     */
    public function show(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $tokenString = $request->query('token');
        $token       = OffboardingToken::where('token', $tokenString)->first();

        if (! $token || ! $token->isValid()) {
            return view('public.offboarding_form_submitted', [
                'error'   => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        return view('public.offboarding_form', [
            'token'   => $token,
            'payload' => $token->payload ?? [],
        ]);
    }

    /**
     * POST /offboarding/respond
     * Manager submits their decision.
     */
    public function submit(
        Request                $request,
        WorkflowEngine         $engine,
        UserProvisioningService $provisioning
    ): View {
        $data = $request->validate([
            'token'    => 'required|string',
            'decision' => 'required|in:approved,rejected',
            'notes'    => 'nullable|string|max:2000',
        ]);

        $token = OffboardingToken::where('token', $data['token'])->first();

        if (! $token) {
            return view('public.offboarding_form_submitted', [
                'error'   => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        $validationFailed = false;
        \DB::transaction(function () use ($token, $data, &$validationFailed) {
            $locked = \App\Models\OffboardingToken::lockForUpdate()->find($token->id);
            if (! $locked || ! $locked->isValid()) {
                $validationFailed = true;
                return;
            }
            // Record manager response
            $locked->update([
                'manager_decision' => $data['decision'],
                'manager_notes'    => $data['notes'] ?? null,
                'responded_at'     => now(),
            ]);
            $locked->markUsed();
        });

        if ($validationFailed) {
            return view('public.offboarding_form_submitted', [
                'error'   => true,
                'message' => 'This link is invalid or has already been used.',
            ]);
        }

        $workflow = $token->workflow;

        if (! $workflow) {
            return view('public.offboarding_form_submitted', [
                'error'   => true,
                'message' => 'The associated workflow could not be found.',
            ]);
        }

        if ($data['decision'] === 'approved') {
            // Update workflow payload with manager notes
            $payload = $workflow->payload ?? [];
            $payload['manager_decision'] = 'approved';
            $payload['manager_notes']    = $data['notes'] ?? null;
            $workflow->payload = $payload;
            $workflow->save();

            // Execute full deprovisioning
            try {
                $engine->logEvent($workflow, 'info', 'Manager approved offboarding. Starting deprovisioning.');
                $provisioning->deprovisionUserFull($workflow);
                $workflow->update(['status' => 'completed']);
                $engine->logEvent($workflow, 'success', 'Offboarding deprovisioning complete.');
            } catch (\Throwable $e) {
                $workflow->update(['status' => 'failed']);
                $engine->logEvent($workflow, 'error', 'Offboarding failed: ' . $e->getMessage());
            }

            // Send HR feedback email
            SendHrFeedbackEmailJob::dispatch($workflow->id, 'offboarding')->onQueue('emails');

        } else {
            // Rejected — mark workflow as cancelled
            $payload = $workflow->payload ?? [];
            $payload['manager_decision'] = 'rejected';
            $payload['manager_notes']    = $data['notes'] ?? null;
            $workflow->payload = $payload;
            $workflow->update(['status' => 'rejected']);
            $engine->logEvent($workflow, 'info', 'Manager rejected offboarding request.');
        }

        return view('public.offboarding_form_submitted', [
            'error'    => false,
            'decision' => $data['decision'],
            'payload'  => collect($workflow->payload ?? [])->except([
                'azure_id', 'national_id', 'hr_id', 'manager_upn', 'manager_azure_id',
            ])->toArray(),
        ]);
    }
}
