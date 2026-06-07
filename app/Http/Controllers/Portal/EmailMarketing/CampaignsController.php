<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\StoreCampaignRequest;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSegment;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\User;
use App\Notifications\CampaignAwaitingApproval;
use App\Services\EmailMarketing\CampaignApprovalService;
use App\Services\EmailMarketing\SpamWordChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class CampaignsController extends Controller
{
    public function index(Request $request): View
    {
        $showArchived = $request->boolean('archived');
        $domain = trim((string) $request->query('domain', ''));

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

        // Pre-flight hint: will sending need approval (external recipients)? This is
        // only a hint — never let recipient resolution (or an unconfigured SES) break
        // the page, so resolve lazily and swallow any failure.
        $needsApproval = false;
        if ($campaign->isEditable()) {
            try {
                $needsApproval = app(CampaignApprovalService::class)->requiresApproval($campaign);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Campaign approval pre-check failed: '.$e->getMessage());
            }
        }

        return view('portal.email-marketing.campaigns.show', compact('campaign', 'needsApproval'));
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

    public function sendNow(Request $request, EmailCampaign $campaign, CampaignApprovalService $approval)
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused'])) {
            return back()->withErrors(['campaign' => 'Campaign is not eligible to send.']);
        }

        if ($approval->requiresApproval($campaign)) {
            $this->submitForApproval($campaign, now(), $approval);

            return redirect()->route('portal.marketing.campaigns.show', $campaign)
                ->with('status', 'This campaign has external recipients — it has been submitted to IT for approval and will send once approved.');
        }

        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => now(),
            'requires_approval' => false,
        ]);

        return redirect()->route('portal.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign queued for immediate send.');
    }

    public function schedule(Request $request, EmailCampaign $campaign, CampaignApprovalService $approval)
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        if ($approval->requiresApproval($campaign)) {
            $this->submitForApproval($campaign, $data['scheduled_at'], $approval);

            return redirect()->route('portal.marketing.campaigns.show', $campaign)
                ->with('status', 'This campaign has external recipients — it has been submitted to IT for approval and will send at the scheduled time once approved.');
        }

        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $data['scheduled_at'],
            'requires_approval' => false,
        ]);

        return redirect()->route('portal.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign scheduled.');
    }

    /**
     * Park the campaign in pending_approval and notify the approvers (super_admins).
     * The requested send time is kept in scheduled_at so it goes out then once
     * approved (for "send now", now() is already past, so it sends immediately).
     */
    private function submitForApproval(EmailCampaign $campaign, $sendAt, CampaignApprovalService $approval): void
    {
        $campaign->update([
            'status' => 'pending_approval',
            'scheduled_at' => $sendAt,
            'requires_approval' => true,
            'submitted_for_approval_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        try {
            $approvers = User::where('role', 'super_admin')->get();
            if ($approvers->isNotEmpty()) {
                Notification::send($approvers, new CampaignAwaitingApproval($campaign, $approval->summary($campaign)));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Campaign approval notification failed: '.$e->getMessage());
        }
    }

    /**
     * Marketing user recalls a campaign awaiting approval, back to draft.
     */
    public function recall(EmailCampaign $campaign)
    {
        if ($campaign->status !== 'pending_approval') {
            return back()->withErrors(['campaign' => 'Only campaigns awaiting approval can be recalled.']);
        }

        $campaign->update([
            'status' => 'draft',
            'requires_approval' => false,
            'submitted_for_approval_at' => null,
        ]);

        return back()->with('status', 'Campaign recalled to draft.');
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
            'email' => $data['to'],
            'first_name' => 'Test',
            'last_name' => 'Recipient',
        ]);
        $renderer = app(\App\Services\EmailMarketing\MergeTagRenderer::class);
        $html = $renderer->render($campaign->template->rendered_html, $fake, null, $campaign->list);

        try {
            $ses = app(\App\Services\EmailMarketing\SesService::class);
            // Pass the campaign's own from_email / from_name / reply_to so the
            // test arrives from the exact sender the real send would use —
            // otherwise sendRawTestEmail falls back to ses_default_from_email
            // and the "Send test" button silently overrides the user's pick.
            $messageId = $ses->sendRawTestEmail(
                $data['to'],
                '[TEST] '.$campaign->subject,
                $html,
                $campaign->from_email,
                $campaign->from_name,
                $campaign->reply_to,
            );

            \App\Models\ActivityLog::create([
                'model_type' => 'EmailCampaign',
                'model_id' => $campaign->id,
                'action' => 'test_sent',
                'changes' => ['to' => $data['to'], 'from' => $campaign->from_email],
                'user_id' => $request->user()->id,
            ]);

            return back()->with('status', "Test email sent to {$data['to']} from {$campaign->from_email} (SES MessageId: {$messageId}).");
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
            'campaign' => $campaign,
            'lists' => EmailList::orderBy('name')->get(['id', 'name']),
            'segments' => EmailSegment::orderBy('name')->get(['id', 'name']),
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'spamHits' => $spamHits,
            'senders' => $senders,
        ];
    }
}
