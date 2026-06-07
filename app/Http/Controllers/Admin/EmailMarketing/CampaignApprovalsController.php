<?php

namespace App\Http\Controllers\Admin\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Notifications\CampaignApproved;
use App\Notifications\CampaignRejected;
use App\Services\EmailMarketing\CampaignApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * IT campaign-approval queue. super_admin only (the owner chose super_admin as the
 * sole approver). Campaigns with external recipients are parked in
 * `pending_approval`; approving flips them to `scheduled` so the dispatcher sends
 * them, rejecting returns them to draft with a reason.
 */
class CampaignApprovalsController extends Controller
{
    public function index(Request $request, CampaignApprovalService $approval): View
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $rows = EmailCampaign::where('status', 'pending_approval')
            ->with(['creator', 'list', 'segment', 'template'])
            ->orderBy('submitted_for_approval_at')
            ->get()
            ->map(fn (EmailCampaign $c) => [
                'campaign' => $c,
                'summary' => $approval->summary($c),
            ]);

        return view('admin.email-marketing.approvals', ['rows' => $rows]);
    }

    public function approve(Request $request, EmailCampaign $campaign)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        if ($campaign->status !== 'pending_approval') {
            return back()->withErrors(['campaign' => 'This campaign is not awaiting approval.']);
        }

        $campaign->update([
            'status' => 'scheduled',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $this->notifyCreator($campaign, new CampaignApproved($campaign));

        return back()->with('status', "Approved \"{$campaign->name}\" — it will send shortly.");
    }

    public function reject(Request $request, EmailCampaign $campaign)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($campaign->status !== 'pending_approval') {
            return back()->withErrors(['campaign' => 'This campaign is not awaiting approval.']);
        }

        $campaign->update([
            'status' => 'draft',
            'requires_approval' => false,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $data['reason'],
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $this->notifyCreator($campaign, new CampaignRejected($campaign, $data['reason']));

        return back()->with('status', "Rejected \"{$campaign->name}\" — the creator has been notified.");
    }

    private function notifyCreator(EmailCampaign $campaign, $notification): void
    {
        try {
            $campaign->creator?->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Campaign decision notification failed: '.$e->getMessage());
        }
    }
}
