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
    public function index(Request $request): View
    {
        $showArchived = $request->boolean('archived');
        $domain       = trim((string) $request->query('domain', ''));

        $campaigns = EmailCampaign::query()
            ->with(['list', 'template'])
            ->when(! $showArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'))
            ->when($domain !== '', fn ($q) => $q->where('from_email', 'like', '%@'.$domain))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        // Populate the domain dropdown from distinct from_email values currently in use.
        $domains = EmailCampaign::query()
            ->whereNotNull('from_email')
            ->where('from_email', '!=', '')
            ->pluck('from_email')
            ->map(fn ($e) => \Illuminate\Support\Str::after($e, '@'))
            ->unique()
            ->filter()
            ->sort()
            ->values()
            ->all();

        return view('portal.email-marketing.campaigns.index', compact('campaigns', 'showArchived', 'domains', 'domain'));
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
            'status', 'scheduled_at', 'started_at', 'sent_at', 'archived_at',
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

    public function archive(EmailCampaign $campaign)
    {
        if (in_array($campaign->status, ['scheduled', 'sending'])) {
            return back()->withErrors(['campaign' => 'Pause the campaign before archiving.']);
        }
        $wasArchived = $campaign->archived_at !== null;
        $campaign->update(['archived_at' => $wasArchived ? null : now()]);

        return back()->with('status', $wasArchived ? 'Campaign restored.' : 'Campaign archived.');
    }

    /**
     * Render the campaign template against a placeholder subscriber and send it
     * via SES to an arbitrary address. Doesn't touch email_campaign_sends or
     * counters — purely a preview send. Subject is prefixed [TEST].
     */
    public function testSend(Request $request, EmailCampaign $campaign)
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:191'],
        ]);

        $campaign->loadMissing(['template', 'list']);
        if (! $campaign->template || ! $campaign->template->rendered_html) {
            return back()->withErrors(['test' => 'This campaign has no template HTML to send.']);
        }

        // Render merge tags with a placeholder subscriber so {{first_name}} etc. resolve
        $fake = new \App\Models\EmailMarketing\EmailSubscriber([
            'email'      => $data['to'],
            'first_name' => 'Test',
            'last_name'  => 'Recipient',
        ]);
        $renderer = app(\App\Services\EmailMarketing\MergeTagRenderer::class);
        $html = $renderer->render($campaign->template->rendered_html, $fake, null, $campaign->list);

        try {
            $ses = app(\App\Services\EmailMarketing\SesService::class);
            $messageId = $ses->sendRawTestEmail($data['to'], '[TEST] '.$campaign->subject, $html);

            return back()->with('status', "Test email sent to {$data['to']} (SES MessageId: {$messageId}).");
        } catch (\App\Services\EmailMarketing\EmailMarketingNotConfiguredException $e) {
            return back()->withErrors(['test' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['test' => 'AWS error: '.$e->getMessage()]);
        }
    }

    private function editPayload(EmailCampaign $campaign): array
    {
        $spamHits = $campaign->subject
            ? app(SpamWordChecker::class)->checkSubject($campaign->subject)
            : [];

        $senders = \App\Models\EmailMarketing\EmailSenderIdentity::active()
            ->orderByDesc('is_default')
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'reply_to', 'is_default']);

        return [
            'campaign'  => $campaign,
            'lists'     => EmailList::orderBy('name')->get(['id', 'name']),
            'segments'  => EmailSegment::orderBy('name')->get(['id', 'name']),
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'spamHits'  => $spamHits,
            'senders'   => $senders,
        ];
    }
}
