<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiBatch;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Models\Archive\ArchiveFile;
use App\Services\Archive\Ai\BatchRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * AI over the archive: what it may cost, which archives it may touch, and the
 * batches that read history or fill its gaps.
 *
 * Nothing on this page reads a page or calls Azure. Every button either changes a
 * setting the worker reads on its next wake, or creates a batch row — the same
 * rule as the Transfer page, and for the same reason: reading 40,000 pages
 * cannot happen inside a request.
 *
 * The estimate is the point of the page. A batch is a decision to spend money
 * across an unbounded number of documents, so the cost is shown BEFORE anything
 * starts, and shown as an estimate — ArcMate recorded 0 for every page count, so
 * the page figure leans on an average of what has already been read and must
 * never be presented as a count.
 */
class AiController extends Controller
{
    public function __construct(private BatchRunner $runner) {}

    public function index(Request $request): View
    {
        $settings = ArchiveAiSettings::get();

        return view('admin.archive.ai', [
            'settings' => $settings,
            'spentThisMonth' => ArchiveAiUsage::spentThisMonth(),
            'remaining' => $settings->remainingBudget(),
            'archives' => Archive::query()->orderBy('sort_order')->orderBy('name')->get(),
            'batches' => ArchiveAiBatch::query()->with('archive')->orderByDesc('id')->limit(15)->get(),
            'pendingProposals' => ArchiveAiProposal::query()->pending()->count(),
            'usageByFeature' => $this->usageByFeature(),
            'readProgress' => $this->readProgress(),
            'types' => [
                ArchiveAiBatch::TYPE_READ => 'Read the pages (makes words searchable)',
                ArchiveAiBatch::TYPE_FILL => 'Propose values for empty fields',
            ],
        ]);
    }

    /**
     * The budget and the caps.
     *
     * Audited automatically — ArchiveAiSettings is deliberately not in
     * config/audit.php's exclusions, because changing what AI may spend is a
     * decision somebody should be able to look up later.
     */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'monthly_budget_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'per_user_daily_pages' => ['required', 'integer', 'min:0', 'max:100000'],
            'page_read_cost_usd' => ['required', 'numeric', 'min:0', 'max:10'],
            // The conversation prices. Validated here as well, or a figure typed
            // on the page is silently dropped by fill() and the spend keeps being
            // measured against whatever the last value was.
            'prompt_token_cost_usd' => ['required', 'numeric', 'min:0', 'max:10'],
            'completion_token_cost_usd' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        ArchiveAiSettings::get()->fill($data)->save();

        return back()->with('status', 'AI settings saved.');
    }

    /** The per-archive switches: may AI answer about it, may AI read it. */
    public function saveArchive(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'ai_chat' => ['nullable', 'boolean'],
            'ai_reading' => ['nullable', 'boolean'],
        ]);

        $archive->forceFill([
            'ai_chat' => (bool) ($data['ai_chat'] ?? false),
            'ai_reading' => (bool) ($data['ai_reading'] ?? false),
        ])->save();

        return back()->with('status', $archive->displayName().' updated.');
    }

    /**
     * What a batch would cost, before anybody commits to it.
     *
     * JSON, so the form can show the figure as the archive and dates change
     * without a page load — and so the number somebody clicks Start next to is
     * the number they were shown.
     */
    public function estimate(Request $request): JsonResponse
    {
        $data = $this->batchRules($request);

        $archive = Archive::find($data['archive_id']);

        if (! $archive) {
            return response()->json(['error' => 'No such archive.'], 404);
        }

        $estimate = $this->runner->estimate(
            $archive,
            $data['type'],
            array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
            array_map('intval', $data['field_ids'] ?? []),
            (int) ($data['max_documents'] ?? 0),
        );

        return response()->json($estimate + [
            'budget_remaining' => round(ArchiveAiSettings::get()->remainingBudget(), 2),
        ]);
    }

    /**
     * Start a batch.
     *
     * Created as a row, not run here: `archive:ai-batch` picks it up within a
     * minute and works it in slices, so a batch over 500,000 documents survives
     * a deploy and never holds a request open.
     */
    public function start(Request $request): RedirectResponse
    {
        $data = $this->batchRules($request);

        $archive = Archive::find($data['archive_id']);

        abort_unless($archive, 404);

        if (! $archive->readable) {
            return back()->with('error', 'That archive is stored encrypted, so its pages cannot be read.');
        }

        if (! $archive->ai_reading) {
            return back()->with('error', 'Switch AI reading on for '.$archive->displayName().' first.');
        }

        if (! ArchiveAiSettings::get()->withinBudget()) {
            return back()->with('error', 'There is no AI budget left this month. Raise the budget first, or the batch would stop as soon as it started.');
        }

        $fieldIds = array_map('intval', $data['field_ids'] ?? []);

        if ($data['type'] === ArchiveAiBatch::TYPE_FILL && $fieldIds === []) {
            return back()->with('error', 'Choose at least one field to fill in.');
        }

        // Only this archive's own fields, so a field id from the form cannot
        // attach a proposal to a field belonging to somewhere else.
        if ($fieldIds !== []) {
            $fieldIds = $archive->fields()->whereIn('id', $fieldIds)->pluck('id')->all();
        }

        $filters = array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);
        $maxDocuments = (int) ($data['max_documents'] ?? 0);
        $estimate = $this->runner->estimate($archive, $data['type'], $filters, $fieldIds, $maxDocuments);

        if ($estimate['documents'] === 0) {
            return back()->with('error', 'Nothing matches that — there is no work to do.');
        }

        $batch = ArchiveAiBatch::create([
            'archive_id' => $archive->getKey(),
            'type' => $data['type'],
            'filters' => $filters ?: null,
            'field_ids' => $fieldIds ?: null,
            'documents_total' => $estimate['documents'],
            'max_documents' => $maxDocuments ?: null,
            'pages_total' => $estimate['pages'],
            'estimated_cost_usd' => $estimate['cost'],
            'requested_by' => $request->user()?->getKey(),
        ]);

        $this->logBatch($batch, 'archive_ai_batch_started', $estimate);

        return back()->with('status', sprintf(
            'Started: %s document(s), about $%s estimated. It runs within a minute and you can pause it any time.',
            number_format($estimate['documents']),
            number_format($estimate['cost'], 2),
        ));
    }

    /** Pause, resume or cancel a batch. */
    public function batchAction(Request $request, ArchiveAiBatch $batch): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:pause,resume,cancel']])['action'];

        if ($batch->isFinished() && $action !== 'resume') {
            return back()->with('error', 'That batch has already finished.');
        }

        if ($action === 'pause') {
            $batch->forceFill(['status' => ArchiveAiBatch::STATUS_PAUSED])->save();
            $this->logBatch($batch, 'archive_ai_batch_paused');

            return back()->with('status', 'Paused. The document in flight finishes first.');
        }

        if ($action === 'resume') {
            // Back to pending rather than running: the worker owns `running`, and
            // a row marked running that nothing is working on reads as a stuck
            // batch on this page.
            $batch->forceFill(['status' => ArchiveAiBatch::STATUS_PENDING, 'error' => null, 'finished_at' => null])->save();
            $this->logBatch($batch, 'archive_ai_batch_resumed');

            return back()->with('status', 'Resumed. It continues where it stopped.');
        }

        $batch->finish(ArchiveAiBatch::STATUS_DONE, 'Cancelled by '.($request->user()?->name ?? 'a reviewer').'.');
        $this->logBatch($batch, 'archive_ai_batch_cancelled');

        return back()->with('status', 'Cancelled. Everything already read is kept, and any proposals wait for review.');
    }

    // ─── Internals ───────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function batchRules(Request $request): array
    {
        return $request->validate([
            'archive_id' => ['required', 'integer'],
            'type' => ['required', 'in:'.ArchiveAiBatch::TYPE_READ.','.ArchiveAiBatch::TYPE_FILL],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'field_ids' => ['nullable', 'array'],
            'field_ids.*' => ['integer'],
            // Blank means no limit: the date range alone decides.
            'max_documents' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
    }

    /** @return array<string,array{pages:int, cost:float}> */
    private function usageByFeature(): array
    {
        return ArchiveAiUsage::query()
            ->whereDate('day', '>=', now()->startOfMonth()->toDateString())
            ->groupBy('feature')
            ->selectRaw('feature, COALESCE(SUM(pages), 0) AS pages, COALESCE(SUM(cost_usd), 0) AS cost')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->feature => ['pages' => (int) $row->pages, 'cost' => (float) $row->cost],
            ])
            ->all();
    }

    /**
     * How much of each archive has been read, counted in FILES.
     *
     * Files rather than pages, because a file's page count is only known once
     * something has opened it — ArcMate recorded zero for all of them.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function readProgress()
    {
        $counts = DB::table('archive_files')
            ->selectRaw('archive_id, text_status, COUNT(*) AS files')
            ->groupBy('archive_id', 'text_status')
            ->get();

        return Archive::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (Archive $archive) use ($counts) {
                $mine = $counts->where('archive_id', $archive->getKey());
                $total = (int) $mine->sum('files');
                $done = (int) ($mine->firstWhere('text_status', ArchiveFile::TEXT_DONE)->files ?? 0);

                return (object) [
                    'archive' => $archive,
                    'total' => $total,
                    'read' => $done,
                    'failed' => (int) ($mine->firstWhere('text_status', ArchiveFile::TEXT_FAILED)->files ?? 0),
                    'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
                ];
            });
    }

    /**
     * Starting, pausing and cancelling a batch are logged by hand: the model is
     * excluded from the automatic audit because its counters are rewritten every
     * minute, but the decision to spend is exactly what an audit trail is for.
     *
     * @param  array<string,mixed>  $extra
     */
    private function logBatch(ArchiveAiBatch $batch, string $action, array $extra = []): void
    {
        try {
            ActivityLog::log($action, $batch, array_filter([
                'archive' => $batch->archive?->slug,
                'type' => $batch->type,
                'documents' => $extra['documents'] ?? $batch->documents_total,
                'estimated_cost_usd' => $extra['cost'] ?? $batch->estimated_cost_usd,
                'cost_so_far_usd' => $batch->cost_so_far_usd,
            ]));
        } catch (\Throwable $e) {
            Log::warning('[archive] AI batch action not logged: '.$e->getMessage());
        }
    }
}
