<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailEvent;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;
use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $now              = now();
        $monthStart       = $now->copy()->startOfMonth();
        $thirtyDaysAgo    = $now->copy()->subDays(30)->startOfDay();
        $sevenDaysAgo     = $now->copy()->subDays(7)->startOfDay();

        // ── Top KPI cards ───────────────────────────────────────────
        $kpis = [
            'subscribers_active'  => EmailSubscriber::where('status', 'subscribed')->count(),
            'subscribers_pending' => EmailSubscriber::where('status', 'pending')->count(),
            'subscribers_bounced' => EmailSubscriber::whereIn('status', ['bounced', 'complained'])->count(),
            'subscribers_total'   => EmailSubscriber::count(),

            'lists'               => EmailList::count(),
            'templates'           => EmailTemplate::whereNull('archived_at')->count(),
            'suppressions'        => EmailSuppression::count(),

            'campaigns_draft'     => EmailCampaign::where('status', 'draft')->whereNull('archived_at')->count(),
            'campaigns_scheduled' => EmailCampaign::where('status', 'scheduled')->count(),
            'campaigns_sending'   => EmailCampaign::where('status', 'sending')->count(),
            'campaigns_sent'      => EmailCampaign::where('status', 'sent')->count(),

            // This-month aggregates
            'sent_this_month'     => (int) EmailCampaign::where('sent_at', '>=', $monthStart)->sum('total_sent'),
            'delivered_this_month'=> (int) EmailCampaign::where('sent_at', '>=', $monthStart)->sum('total_delivered'),
            'opens_this_month'    => (int) EmailCampaign::where('sent_at', '>=', $monthStart)->sum('total_unique_opens'),
            'clicks_this_month'   => (int) EmailCampaign::where('sent_at', '>=', $monthStart)->sum('total_unique_clicks'),
            'bounces_this_month'  => (int) EmailCampaign::where('sent_at', '>=', $monthStart)->sum('total_bounces'),
        ];

        $kpis['avg_open_rate']  = $kpis['delivered_this_month'] > 0
            ? round($kpis['opens_this_month'] / $kpis['delivered_this_month'] * 100, 1) : 0;
        $kpis['avg_click_rate'] = $kpis['delivered_this_month'] > 0
            ? round($kpis['clicks_this_month'] / $kpis['delivered_this_month'] * 100, 1) : 0;
        $kpis['avg_bounce_rate'] = $kpis['sent_this_month'] > 0
            ? round($kpis['bounces_this_month'] / $kpis['sent_this_month'] * 100, 1) : 0;

        // ── Volume over time (last 30 days, daily) ─────────────────
        $volumeRaw = DB::table('email_campaign_sends')
            ->where('sent_at', '>=', $thirtyDaysAgo)
            ->selectRaw("DATE(sent_at) as day, COUNT(*) as c")
            ->groupBy('day')->orderBy('day')->get();
        $volumeSeries = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->format('Y-m-d');
            $volumeSeries[] = [
                'day' => $day,
                'count' => (int) ($volumeRaw->firstWhere('day', $day)?->c ?? 0),
            ];
        }

        // ── Engagement breakdown (last 30 days, donut data) ─────────
        $engagementRaw = DB::table('email_events as e')
            ->join('email_campaign_sends as s', 's.id', '=', 'e.email_campaign_send_id')
            ->where('e.created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('e.event_type, COUNT(*) as c')
            ->groupBy('e.event_type')->pluck('c', 'event_type');

        $engagement = [
            'Delivery'  => (int) ($engagementRaw['Delivery']  ?? 0),
            'Open'      => (int) ($engagementRaw['Open']      ?? 0),
            'Click'     => (int) ($engagementRaw['Click']     ?? 0),
            'Bounce'    => (int) ($engagementRaw['Bounce']    ?? 0),
            'Complaint' => (int) ($engagementRaw['Complaint'] ?? 0),
        ];

        // ── Top campaigns by open rate (last 90 days, min 10 delivered) ─
        $topByOpens = EmailCampaign::query()
            ->where('sent_at', '>=', $now->copy()->subDays(90))
            ->where('total_delivered', '>=', 10)
            ->orderByRaw('total_unique_opens / GREATEST(total_delivered, 1) DESC')
            ->limit(5)
            ->get(['id', 'name', 'sent_at', 'total_delivered', 'total_unique_opens', 'total_unique_clicks']);

        // ── Recent campaigns ────────────────────────────────────────
        $recentCampaigns = EmailCampaign::with(['list', 'template'])
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->limit(6)
            ->get();

        // ── Lists with subscriber counts ───────────────────────────
        $lists = EmailList::withCount('subscribers')->orderBy('name')->limit(6)->get();

        // ── Recent bounces / complaints (7d) ───────────────────────
        $recentIssues = EmailEvent::query()
            ->join('email_campaign_sends as s', 's.id', '=', 'email_events.email_campaign_send_id')
            ->join('email_campaigns as c', 'c.id', '=', 's.email_campaign_id')
            ->leftJoin('email_subscribers as sub', 'sub.id', '=', 'email_events.email_subscriber_id')
            ->whereIn('email_events.event_type', ['Bounce', 'Complaint'])
            ->where('email_events.created_at', '>=', $sevenDaysAgo)
            ->select(
                'email_events.id', 'email_events.event_type', 'email_events.bounce_type',
                'email_events.created_at', 'sub.email as subscriber_email',
                'c.id as campaign_id', 'c.name as campaign_name', 's.id as send_id',
            )
            ->orderByDesc('email_events.created_at')
            ->limit(10)
            ->get();

        return view('portal.email-marketing.dashboard', compact(
            'kpis', 'volumeSeries', 'engagement',
            'topByOpens', 'recentCampaigns', 'lists', 'recentIssues',
        ));
    }
}
