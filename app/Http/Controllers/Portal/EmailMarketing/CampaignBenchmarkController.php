<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Side-by-side comparison of any 2+ campaigns on a chosen rate metric.
 * Picker + horizontal bar chart + summary table — all numbers come from
 * the pre-aggregated counter columns on email_campaigns, no extra queries.
 */
class CampaignBenchmarkController extends Controller
{
    /** Available metrics → human label + the EmailCampaign accessor method to call. */
    public const METRICS = [
        'delivery_rate'  => ['label' => 'Delivery rate',  'method' => 'deliveryRate',  'color' => '#0d6efd'],
        'open_rate'      => ['label' => 'Open rate',      'method' => 'openRate',      'color' => '#198754'],
        'click_rate'     => ['label' => 'Click rate',     'method' => 'clickRate',     'color' => '#0dcaf0'],
        'bounce_rate'    => ['label' => 'Bounce rate',    'method' => 'bounceRate',    'color' => '#dc3545'],
        'complaint_rate' => ['label' => 'Complaint rate', 'method' => 'complaintRate', 'color' => '#ffc107'],
    ];

    public function show(Request $request): View
    {
        $metric = (string) $request->query('metric', 'open_rate');
        if (! isset(self::METRICS[$metric])) {
            $metric = 'open_rate';
        }

        // Selected campaign ids — default to the 5 most recent sent campaigns.
        $selectedIds = collect($request->query('campaigns', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($selectedIds)) {
            $selectedIds = EmailCampaign::query()
                ->whereIn('status', ['sent', 'sending'])
                ->whereNull('archived_at')
                ->latest('sent_at')
                ->limit(5)
                ->pluck('id')
                ->all();
        }

        $selected = EmailCampaign::query()
            ->with('list')
            ->whereIn('id', $selectedIds)
            ->get()
            ->sortBy(function ($c) use ($selectedIds) {
                return array_search($c->id, $selectedIds);
            })
            ->values();

        // Catalog for the picker — only non-draft, non-archived for compare-ability.
        $catalog = EmailCampaign::query()
            ->whereIn('status', ['sent', 'sending'])
            ->whereNull('archived_at')
            ->orderByDesc('sent_at')
            ->limit(200)
            ->get(['id', 'name', 'sent_at', 'total_sent', 'total_delivered']);

        $metricMethod = self::METRICS[$metric]['method'];
        $rows = $selected->map(fn ($c) => [
            'id'              => $c->id,
            'name'            => $c->name,
            'sent_at'         => $c->sent_at?->format('Y-m-d') ?? '—',
            'list'            => $c->list?->name ?? '—',
            'total_sent'      => $c->total_sent,
            'total_delivered' => $c->total_delivered,
            'value'           => $c->{$metricMethod}(),
            'delivery_rate'   => $c->deliveryRate(),
            'open_rate'       => $c->openRate(),
            'click_rate'      => $c->clickRate(),
            'bounce_rate'     => $c->bounceRate(),
            'complaint_rate'  => $c->complaintRate(),
        ]);

        return view('portal.email-marketing.campaigns.benchmark', [
            'metric'         => $metric,
            'metricLabel'    => self::METRICS[$metric]['label'],
            'metricColor'    => self::METRICS[$metric]['color'],
            'metrics'        => self::METRICS,
            'catalog'        => $catalog,
            'selected'       => $selected,
            'rows'           => $rows,
            'selectedIds'    => $selectedIds,
        ]);
    }
}
