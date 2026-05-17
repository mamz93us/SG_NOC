<?php

namespace App\Http\Controllers\Admin\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Services\EmailMarketing\EmailMarketingNotConfiguredException;
use App\Services\EmailMarketing\SesService;
use Illuminate\View\View;

class QuotaController extends Controller
{
    public function index(): View
    {
        $quota = null;
        $error = null;
        $stats = [];

        try {
            $ses = app(SesService::class);
            $quota = $ses->getSendQuota();
            $stats = $ses->getSendStatistics();
        } catch (EmailMarketingNotConfiguredException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = 'AWS error: '.$e->getMessage();
        }

        $campaignCounts = EmailCampaign::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $subscribers = [
            'subscribed' => EmailSubscriber::where('status', 'subscribed')->count(),
            'pending' => EmailSubscriber::where('status', 'pending')->count(),
            'unsubscribed' => EmailSubscriber::where('status', 'unsubscribed')->count(),
            'bounced' => EmailSubscriber::where('status', 'bounced')->count(),
            'complained' => EmailSubscriber::where('status', 'complained')->count(),
        ];

        return view('admin.email-marketing.quota', [
            'quota' => $quota,
            'error' => $error,
            'stats' => $stats,
            'campaignCounts' => $campaignCounts,
            'subscribers' => $subscribers,
        ]);
    }
}
