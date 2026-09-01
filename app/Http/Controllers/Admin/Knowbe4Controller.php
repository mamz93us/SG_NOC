<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Services\KnowBe4\KnowBe4Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Security Awareness (KnowBe4).
 *
 * The employee portal shows a person only their OWN score, deliberately. This
 * page is the counterpart for whoever runs security awareness: the whole
 * roster, so they can see who is at risk and who has training outstanding.
 *
 * It is a genuinely different audience, so it is gated behind its own
 * permission rather than riding on `manage-settings` — someone who can edit
 * settings does not automatically need every colleague's phishing record.
 */
class Knowbe4Controller extends Controller
{
    public function index(Request $request): View
    {
        $settings = Setting::get();

        return view('admin.knowbe4.index', [
            'scores' => $this->query($request)->paginate(50)->withQueryString(),
            'settings' => $settings,
            'stats' => $this->stats(),
            'q' => trim((string) $request->input('q')),
            'band' => $request->input('band'),
            'matched' => $request->input('matched'),
        ]);
    }

    /**
     * Runs the sync inline rather than queueing it.
     *
     * Production has no queue worker — the scheduler is the worker (see
     * CLAUDE.md), so a dispatched job would sit until the next minute tick at
     * best. The sync is two paginated calls and takes seconds, so blocking the
     * request is the honest option and the one that can report what happened.
     */
    public function sync(KnowBe4Service $kb4): RedirectResponse
    {
        $settings = Setting::get();

        if (! $kb4->isConfigured($settings)) {
            return back()->with('error',
                'KnowBe4 is not enabled or has no API token. Set it under Settings → Employee Home Portal.');
        }

        // A slow KnowBe4 or a large roster must not die half way to a 504 and
        // leave the operator guessing.
        @set_time_limit(300);

        try {
            $exit = Artisan::call('knowbe4:sync');
            $output = trim(Artisan::output());

            return $exit === 0
                ? back()->with('success', "KnowBe4 sync completed.\n".$output)
                : back()->with('error', "KnowBe4 sync finished with errors.\n".$output);
        } catch (\Throwable $e) {
            return back()->with('error', 'KnowBe4 sync failed: '.$e->getMessage());
        }
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'knowbe4-scores-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Name', 'Email', 'Department', 'Branch', 'Phish-Prone %', 'PPP Band', 'Risk Score',
                'Phishing Fails', 'Phishing Sent', 'Training Completed',
                'Training Outstanding', 'Status', 'Last Phish Failed', 'Synced At',
            ]);

            // chunkById, not get(): a thousand rows is fine today but this is
            // the query most likely to grow.
            $this->query($request)->with('employee.department', 'employee.branch')
                ->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $s) {
                        fputcsv($out, [
                            $s->employee?->name ?? '',
                            $s->email,
                            $s->employee?->department?->name ?? '',
                            $s->employee?->branch?->name ?? '',
                            $s->phish_prone_percentage,
                            $s->pppBand(),
                            $s->risk_score,
                            $s->phish_fail_count,
                            $s->phish_sent_count,
                            $s->trainings_completed,
                            $s->trainings_outstanding,
                            $s->status,
                            $s->last_phish_failed_at?->toDateString(),
                            $s->synced_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function query(Request $request)
    {
        $query = Knowbe4Score::query()->with('employee');

        if ($search = trim((string) $request->input('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            });
        }

        // Phish-Prone Percentage, matching Knowbe4Score::pppBand() and the
        // portal card. Higher is worse. `risk_score` is still synced and still
        // in the CSV, it just is not what this page leads with any more: PPP
        // says one concrete thing (share of tests failed) where risk is an
        // opaque composite.
        match ($request->input('band')) {
            'high' => $query->where('phish_prone_percentage', '>=', 30),
            'medium' => $query->whereBetween('phish_prone_percentage', [10, 29.99]),
            'low' => $query->where('phish_prone_percentage', '<', 10),
            default => null,
        };

        match ($request->input('matched')) {
            'yes' => $query->whereNotNull('employee_id'),
            'no' => $query->whereNull('employee_id'),
            default => null,
        };

        // Worst first — this page exists to find people at risk, so the useful
        // rows should be on screen without sorting.
        return $query->orderByDesc('phish_prone_percentage')->orderBy('email');
    }

    private function stats(): array
    {
        return [
            'total' => Knowbe4Score::count(),
            'matched' => Knowbe4Score::whereNotNull('employee_id')->count(),
            'unmatched' => Knowbe4Score::whereNull('employee_id')->count(),
            'high' => Knowbe4Score::where('phish_prone_percentage', '>=', 30)->count(),
            'medium' => Knowbe4Score::whereBetween('phish_prone_percentage', [10, 29.99])->count(),
            'low' => Knowbe4Score::where('phish_prone_percentage', '<', 10)->count(),
            'training_due' => Knowbe4Score::where('trainings_outstanding', '>', 0)->count(),
            'avg' => round((float) Knowbe4Score::avg('phish_prone_percentage'), 1),
        ];
    }
}
