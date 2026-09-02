<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailEvent;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SES mail-delivery log — every message AWS SES told us about, and what
 * happened to it.
 *
 * Distinct from Admin\EmailLogController ("Email Send Log"), which records what
 * *this app* handed to the mailer. This one is the outcome side, reported by
 * SES itself, and covers the whole AWS account: NOC alerts, workflow and
 * onboarding mail, and marketing campaigns alike.
 *
 * Read-only. The rows are a projection of email_events, owned by the SNS
 * webhook; nothing here writes.
 */
class MailDeliveryController extends Controller
{
    /** One row per message. */
    public function index(Request $request): View
    {
        $messages = EmailMessage::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q
                    ->where('to_email', 'like', $term)
                    ->orWhere('from_email', 'like', $term)
                    ->orWhere('recipients', 'like', $term)
                    ->orWhere('subject', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('opened'), fn ($q) => $q->where('open_count', '>', 0))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('sent_at', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('sent_at', '<=', $request->date('to_date')))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.mail-delivery.index', [
            'messages' => $messages,
            'stats' => $this->stats(),
            'senders' => $this->senders(),
        ]);
    }

    /** One message, with its full SES event timeline. */
    public function show(EmailMessage $message): View
    {
        $message->load('campaignSend');

        return view('admin.mail-delivery.show', [
            'message' => $message,
            'events' => $message->events()->get(),
        ]);
    }

    /** The raw event feed, for chasing deliverability rather than a message. */
    public function events(Request $request): View
    {
        $events = EmailEvent::query()
            ->when($request->filled('type'), fn ($q) => $q->where('event_type', $request->string('type')))
            ->when($request->filled('q'), function ($query) use ($request) {
                // email_events has no address columns of its own, so match
                // through the message projection rather than scanning JSON.
                $term = '%'.$request->string('q').'%';
                $ids = EmailMessage::where('to_email', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->limit(5000)
                    ->pluck('ses_message_id');
                $query->whereIn('ses_message_id', $ids);
            })
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        // One lookup for the whole page, so the table can show a subject
        // without running a query per row.
        $messages = EmailMessage::whereIn('ses_message_id', $events->pluck('ses_message_id')->filter()->unique())
            ->get()
            ->keyBy('ses_message_id');

        return view('admin.mail-delivery.events', compact('events', 'messages'));
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /** Headline counts for the last 30 days. */
    private function stats(): array
    {
        $byStatus = EmailMessage::where('sent_at', '>=', now()->subDays(30))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'delivered' => (int) ($byStatus['delivered'] ?? 0),
            'bounced' => (int) ($byStatus['bounced'] ?? 0),
            'complained' => (int) ($byStatus['complained'] ?? 0),
            'pending' => (int) ($byStatus['sent'] ?? 0),
        ];
    }

    /** Distinct senders, for the filter dropdown. Few enough to list. */
    private function senders(): array
    {
        return EmailMessage::query()
            ->whereNotNull('from_email')
            ->distinct()
            ->orderBy('from_email')
            ->limit(50)
            ->pluck('from_email')
            ->all();
    }
}
