<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CampaignAnalyticsController extends Controller
{
    public function show(EmailCampaign $campaign): View
    {
        $campaign->load(['list', 'template']);

        // Top clicked links — single indexed query
        $topLinks = DB::table('email_events as e')
            ->join('email_campaign_sends as s', 's.id', '=', 'e.email_campaign_send_id')
            ->where('s.email_campaign_id', $campaign->id)
            ->where('e.event_type', 'Click')
            ->selectRaw('e.url, COUNT(*) as clicks')
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

        return view('portal.email-marketing.campaigns.analytics', [
            'campaign' => $campaign,
            'topLinks' => $topLinks,
            'timeSeries' => $timeSeries,
        ]);
    }
}
