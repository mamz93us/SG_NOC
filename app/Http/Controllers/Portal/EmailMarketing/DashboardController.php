<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $kpis = [
            'subscribers_active' => EmailSubscriber::where('status', 'subscribed')->count(),
            'subscribers_pending' => EmailSubscriber::where('status', 'pending')->count(),
            'lists' => EmailList::count(),
            'templates' => EmailTemplate::count(),
            'campaigns_draft' => EmailCampaign::where('status', 'draft')->count(),
            'campaigns_scheduled' => EmailCampaign::where('status', 'scheduled')->count(),
            'campaigns_sent' => EmailCampaign::where('status', 'sent')->count(),
        ];

        $recentCampaigns = EmailCampaign::with(['list', 'template'])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        return view('portal.email-marketing.dashboard', compact('kpis', 'recentCampaigns'));
    }
}
