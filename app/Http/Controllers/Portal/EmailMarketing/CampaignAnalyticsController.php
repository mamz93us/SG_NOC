<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CampaignAnalyticsController extends Controller
{
    public function show(Request $request, EmailCampaign $campaign): View
    {
        $campaign->load(['list', 'template']);

        // Top clicked links — single indexed query
        $topLinks = DB::table('email_events as e')
            ->join('email_campaign_sends as s', 's.id', '=', 'e.email_campaign_send_id')
            ->where('s.email_campaign_id', $campaign->id)
            ->where('e.event_type', 'Click')
            ->selectRaw('e.url, COUNT(*) as clicks, COUNT(DISTINCT e.email_subscriber_id) as unique_clicks')
            ->groupBy('e.url')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();

        // Activity over time (24h after send) — events per hour
        $start = $campaign->sent_at ?? $campaign->started_at ?? $campaign->updated_at;
        $end = $start ? $start->copy()->addHours(24) : now();
        $timeSeries = DB::table('email_events as e')
            ->join('email_campaign_sends as s', 's.id', '=', 'e.email_campaign_send_id')
            ->where('s.email_campaign_id', $campaign->id)
            ->whereIn('e.event_type', ['Open', 'Click', 'Bounce'])
            ->whereBetween('e.created_at', [$start, $end])
            ->selectRaw("DATE_FORMAT(e.created_at, '%Y-%m-%d %H:00') as hour, e.event_type, COUNT(*) as c")
            ->groupBy('hour', 'e.event_type')
            ->orderBy('hour')
            ->get();

        // ── Per-recipient activity table (paginated) ─────────────────
        // For every send row, show: subscriber email + name + send status +
        // opens/clicks counts + last activity + last IP.
        $recipientFilter = (string) $request->query('recipient_status', '');
        $recipientQuery = trim((string) $request->query('q', ''));

        $recipients = EmailCampaignSend::query()
            ->where('email_campaign_sends.email_campaign_id', $campaign->id)
            ->join('email_subscribers as sub', 'sub.id', '=', 'email_campaign_sends.email_subscriber_id')
            ->leftJoin(DB::raw("(
                SELECT email_campaign_send_id,
                       SUM(event_type = 'Open')   AS opens,
                       SUM(event_type = 'Click')  AS clicks,
                       MAX(created_at)            AS last_activity_at,
                       SUBSTRING_INDEX(GROUP_CONCAT(ip_address ORDER BY created_at DESC), ',', 1) AS last_ip,
                       SUBSTRING_INDEX(GROUP_CONCAT(user_agent ORDER BY created_at DESC SEPARATOR '||'), '||', 1) AS last_user_agent
                FROM email_events
                WHERE event_type IN ('Open','Click','Bounce','Complaint','Delivery')
                GROUP BY email_campaign_send_id
            ) AS agg"), 'agg.email_campaign_send_id', '=', 'email_campaign_sends.id')
            ->select(
                'email_campaign_sends.id as send_id',
                'email_campaign_sends.status as send_status',
                'email_campaign_sends.ses_message_id',
                'email_campaign_sends.sent_at',
                'email_campaign_sends.delivered_at',
                'email_campaign_sends.error_message',
                'sub.id as subscriber_id',
                'sub.email',
                'sub.first_name',
                'sub.last_name',
                'sub.status as subscriber_status',
                DB::raw('COALESCE(agg.opens, 0) as opens'),
                DB::raw('COALESCE(agg.clicks, 0) as clicks'),
                'agg.last_activity_at',
                'agg.last_ip',
                'agg.last_user_agent',
            )
            ->when($recipientFilter !== '', fn ($q) => $q->where('email_campaign_sends.status', $recipientFilter))
            ->when($recipientQuery !== '', fn ($q) => $q->where(function ($x) use ($recipientQuery) {
                $x->where('sub.email', 'like', '%'.$recipientQuery.'%')
                  ->orWhere('sub.first_name', 'like', '%'.$recipientQuery.'%')
                  ->orWhere('sub.last_name', 'like', '%'.$recipientQuery.'%');
            }))
            ->orderByDesc(DB::raw('COALESCE(agg.last_activity_at, email_campaign_sends.sent_at)'))
            ->paginate(50, ['*'], 'recipients_page')
            ->withQueryString();

        // ── Detailed event log (paginated) ───────────────────────────
        // Per-event row: timestamp, subscriber, event, URL, IP, user agent.
        $eventFilter = (string) $request->query('event_type', '');

        $events = EmailEvent::query()
            ->join('email_campaign_sends as s', 's.id', '=', 'email_events.email_campaign_send_id')
            ->leftJoin('email_subscribers as sub', 'sub.id', '=', 'email_events.email_subscriber_id')
            ->where('s.email_campaign_id', $campaign->id)
            ->when($eventFilter !== '', fn ($q) => $q->where('email_events.event_type', $eventFilter))
            ->select(
                'email_events.id',
                'email_events.event_type',
                'email_events.url',
                'email_events.ip_address',
                'email_events.user_agent',
                'email_events.bounce_type',
                'email_events.bounce_subtype',
                'email_events.complaint_type',
                'email_events.created_at',
                'sub.email as subscriber_email',
                'sub.first_name as subscriber_first_name',
                'sub.last_name as subscriber_last_name',
            )
            ->orderByDesc('email_events.created_at')
            ->paginate(100, ['*'], 'events_page')
            ->withQueryString();

        return view('portal.email-marketing.campaigns.analytics', [
            'campaign' => $campaign,
            'topLinks' => $topLinks,
            'timeSeries' => $timeSeries,
            'recipients' => $recipients,
            'events' => $events,
            'recipientFilter' => $recipientFilter,
            'recipientQuery' => $recipientQuery,
            'eventFilter' => $eventFilter,
        ]);
    }
}
