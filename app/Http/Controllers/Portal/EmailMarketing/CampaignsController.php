<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\StoreCampaignRequest;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSegment;
use App\Models\EmailMarketing\EmailTemplate;
use App\Services\EmailMarketing\SpamWordChecker;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CampaignsController extends Controller
{
    public function index(): View
    {
        $campaigns = EmailCampaign::with(['list', 'template'])
            ->latest('updated_at')
            ->paginate(25);

        return view('portal.email-marketing.campaigns.index', compact('campaigns'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.campaigns.edit', $this->editPayload(new EmailCampaign));
    }

    public function store(StoreCampaignRequest $request)
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? 'draft';
        $data['created_by'] = $request->user()->id;
        $campaign = EmailCampaign::create($data);

        return redirect()->route('portal.marketing.campaigns.edit', $campaign)
            ->with('status', 'Campaign created.');
    }

    public function show(EmailCampaign $campaign): View
    {
        $campaign->load(['list', 'segment', 'template', 'creator']);

        return view('portal.email-marketing.campaigns.show', compact('campaign'));
    }

    public function edit(EmailCampaign $campaign): View
    {
        if (! $campaign->isEditable()) {
            return view('portal.email-marketing.campaigns.show',
                ['campaign' => $campaign->load(['list', 'segment', 'template', 'creator'])]);
        }

        return view('portal.email-marketing.campaigns.edit', $this->editPayload($campaign));
    }

    public function update(StoreCampaignRequest $request, EmailCampaign $campaign)
    {
        if (! $campaign->isEditable()) {
            return back()->withErrors(['campaign' => 'This campaign cannot be edited.']);
        }
        $campaign->update($request->validated());

        return redirect()->route('portal.marketing.campaigns.edit', $campaign)
            ->with('status', 'Campaign updated.');
    }

    public function destroy(EmailCampaign $campaign)
    {
        if (! in_array($campaign->status, ['draft', 'paused', 'failed'])) {
            return back()->withErrors(['campaign' => 'Cannot delete a campaign in progress or sent.']);
        }
        $campaign->delete();

        return redirect()->route('portal.marketing.campaigns.index')
            ->with('status', 'Campaign deleted.');
    }

    public function sendNow(Request $request, EmailCampaign $campaign)
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused'])) {
            return back()->withErrors(['campaign' => 'Campaign is not eligible to send.']);
        }
        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => now(),
        ]);

        return redirect()->route('portal.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign queued for immediate send.');
    }

    public function schedule(Request $request, EmailCampaign $campaign)
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);
        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $data['scheduled_at'],
        ]);

        return redirect()->route('portal.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign scheduled.');
    }

    public function pause(EmailCampaign $campaign)
    {
        if (! in_array($campaign->status, ['scheduled', 'sending'])) {
            return back()->withErrors(['campaign' => 'Only scheduled or sending campaigns can be paused.']);
        }
        $campaign->update(['status' => 'paused']);

        return back()->with('status', 'Campaign paused.');
    }

    public function duplicate(EmailCampaign $campaign)
    {
        $copy = $campaign->replicate([
            'status', 'scheduled_at', 'started_at', 'sent_at',
            'total_recipients', 'total_sent', 'total_delivered',
            'total_opens', 'total_unique_opens',
            'total_clicks', 'total_unique_clicks',
            'total_bounces', 'total_complaints', 'total_unsubscribes',
        ]);
        $copy->name = $campaign->name.' (copy)';
        $copy->status = 'draft';
        $copy->save();

        return redirect()->route('portal.marketing.campaigns.edit', $copy)
            ->with('status', 'Campaign duplicated.');
    }

    private function editPayload(EmailCampaign $campaign): array
    {
        $spamHits = $campaign->subject
            ? app(SpamWordChecker::class)->checkSubject($campaign->subject)
            : [];

        return [
            'campaign' => $campaign,
            'lists' => EmailList::orderBy('name')->get(['id', 'name']),
            'segments' => EmailSegment::orderBy('name')->get(['id', 'name']),
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'spamHits' => $spamHits,
        ];
    }
}
