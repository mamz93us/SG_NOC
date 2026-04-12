<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\IdentityGroup;
use App\Models\NetworkFloor;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowRequest;
use Illuminate\Http\Request;

class OnboardingFormController extends Controller
{
    /**
     * GET /onboarding/form/{token}
     * Show the manager setup form (public — no auth required).
     */
    public function show(string $token)
    {
        $tokenRecord = OnboardingManagerToken::where('token', $token)->first();

        if (! $tokenRecord || ! $tokenRecord->isValid()) {
            return view('public.onboarding_form_expired');
        }

        $workflow = $tokenRecord->workflow;
        $payload  = $workflow?->payload ?? [];

        // Load floors scoped to the workflow's branch
        $branch = $workflow?->branch;
        $floors = NetworkFloor::when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Only show Distribution groups (mail-enabled, not security, not M365 Unified).
        // Security groups and M365 groups are internal IT groups managed automatically.
        $groups = IdentityGroup::where('security_enabled', false)
            ->where('mail_enabled', true)
            ->whereNull('group_type')   // Unified = M365; null = Distribution
            ->orderBy('display_name')
            ->get();

        return view('public.onboarding_form', compact('tokenRecord', 'workflow', 'payload', 'floors', 'groups'));
    }

    /**
     * POST /onboarding/form/{token}
     * Handle manager form submission.
     */
    public function submit(Request $request, string $token)
    {
        $tokenRecord = OnboardingManagerToken::where('token', $token)->first();

        if (! $tokenRecord || ! $tokenRecord->isValid()) {
            return view('public.onboarding_form_expired');
        }

        $data = $request->validate([
            'laptop_status'    => 'required|in:new,used,none',
            'needs_extension'  => 'required|in:yes,no',
            'internet_level'   => 'required|in:business,site,high,vip',
            'floor_id'         => 'nullable|exists:network_floors,id',
            'selected_groups'  => 'nullable|array',
            'selected_groups.*'=> 'integer|exists:identity_groups,id',
            'manager_comments' => 'nullable|string|max:2000',
        ]);

        // Extra safety: re-validate submitted IDs are distribution groups only.
        // Prevents a crafted POST from assigning security or M365 groups.
        if (! empty($data['selected_groups'])) {
            $allowedIds = IdentityGroup::whereIn('id', $data['selected_groups'])
                ->where('security_enabled', false)
                ->where('mail_enabled', true)
                ->whereNull('group_type')
                ->pluck('id')
                ->toArray();
            $data['selected_groups'] = $allowedIds;
        }

        $tokenRecord->update([
            'laptop_status'      => $data['laptop_status'],
            'needs_extension'    => $data['needs_extension'] === 'yes',
            'internet_level'     => $data['internet_level'],
            'floor_id'           => $data['floor_id'] ?? null,
            'selected_group_ids' => $data['selected_groups'] ?? [],
            'manager_comments'   => $data['manager_comments'] ?? null,
            'responded_at'       => now(),
        ]);

        // Store manager choices in the workflow payload for UserProvisioningService
        $workflow = $tokenRecord->workflow;
        if ($workflow) {
            $payload = array_merge($workflow->payload ?? [], [
                'manager_form_token_id' => $tokenRecord->id,
                'laptop_status'         => $data['laptop_status'],
                'needs_extension'       => $data['needs_extension'] === 'yes',
                'internet_level'        => $data['internet_level'],
                'floor_id'              => $data['floor_id'] ?? null,
                'manager_groups'        => $data['selected_groups'] ?? [],
                'manager_comments'      => $data['manager_comments'] ?? null,
            ]);
            $workflow->payload = $payload;
            $workflow->save();
        }

        $tokenRecord->markUsed();

        return view('public.onboarding_form_submitted', compact('tokenRecord', 'workflow'));
    }
}
