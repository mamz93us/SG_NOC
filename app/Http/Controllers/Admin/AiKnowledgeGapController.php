<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeGap;
use App\Models\AiSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Ai\KnowledgeGapService;
use App\Services\Ai\KnowledgeIndexer;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Ai\TextTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AI Assistant ▸ Knowledge gaps: the questions the assistant could not
 * answer, and where a person answers them so that it can from then on.
 *
 * Its own permission, answer-ai-knowledge-gaps, rather than
 * manage-ai-assistant: HR answers policy questions here without the
 * assistant's settings, instructions or imports. An answer is published as an
 * ordinary knowledge article; nothing about how the assistant searches changes.
 */
class AiKnowledgeGapController extends Controller
{
    public function __construct(private KnowledgeGapService $service) {}

    public function index(): View
    {
        $floor = $this->floor();

        $open = AiKnowledgeGap::open()
            ->with('bestArticle:id,title,is_published')
            ->orderByDesc('hit_count')
            ->orderByDesc('last_seen_at')
            ->get();

        $groups = $open
            ->groupBy(fn (AiKnowledgeGap $gap) => $gap->group_id ?? $gap->id)
            ->map(function (Collection $gaps) use ($floor) {
                $gaps = $gaps->sortByDesc('hit_count')->values();
                $closest = $gaps
                    ->filter(fn (AiKnowledgeGap $gap) => $gap->best_score !== null && $gap->bestArticle?->is_published)
                    ->sortByDesc('best_score')
                    ->first();

                return [
                    'lead' => $gaps->first(),
                    'gaps' => $gaps,
                    'hits' => (int) $gaps->sum('hit_count'),
                    'last_seen' => $gaps->max('last_seen_at'),
                    'sources' => $gaps->pluck('source')->unique()->values()->all(),
                    'suggestion' => $closest && $closest->best_score >= $floor ? $closest : null,
                ];
            })
            ->sortByDesc('hits')
            ->values();

        return view('admin.ai-knowledge-gaps.index', [
            'groups' => $groups,
            'openCount' => $open->count(),
            'asks' => (int) $open->sum('hit_count'),
            'closedRecently' => AiKnowledgeGap::query()
                ->where('resolved_at', '>=', now()->subDays(30))
                ->selectRaw('resolution, COUNT(*) AS closed')
                ->groupBy('resolution')
                ->pluck('closed', 'resolution'),
            'resolved' => AiKnowledgeGap::query()
                ->whereNotNull('resolved_at')
                ->with(['article:id,title', 'resolver:id,name'])
                ->orderByDesc('resolved_at')
                ->limit(30)
                ->get(),
            'floor' => $floor,
            'lastChecked' => AiKnowledgeGap::max('checked_at'),
        ]);
    }

    public function answerForm(AiKnowledgeGap $aiKnowledgeGap): View|RedirectResponse
    {
        $gaps = $this->groupOf($aiKnowledgeGap);

        if ($gaps->isEmpty()) {
            return redirect()
                ->route('admin.ai-assistant.knowledge-gaps.index')
                ->with('error', 'That question has already been closed.');
        }

        return view('admin.ai-knowledge-gaps.answer', [
            'gaps' => $gaps,
            'lead' => $gaps->first(),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'canTranslate' => AiSetting::get()->isConfigured(),
        ]);
    }

    public function answer(Request $request, KnowledgeIndexer $indexer): RedirectResponse
    {
        $data = $request->validate([
            'gap_ids' => 'required|array|min:1',
            'gap_ids.*' => 'integer',
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'body' => 'required|string|max:20000',
            'body_ar' => 'nullable|string|max:20000',
            'category' => 'nullable|string|max:50',
            'audience' => ['required', Rule::in(['all', 'branch', 'department'])],
            'audience_branch_id' => 'nullable|required_if:audience,branch|integer|exists:branches,id',
            'audience_department_id' => 'nullable|required_if:audience,department|integer|exists:departments,id',
        ], [
            'body.required' => 'Write the answer in English, or write it in Arabic and use Translate to fill in the English.',
        ], [
            'body' => 'English answer',
            'body_ar' => 'Arabic answer',
            'audience_branch_id' => 'branch',
            'audience_department_id' => 'department',
        ]);

        $gaps = AiKnowledgeGap::open()->whereIn('id', $data['gap_ids'])->orderByDesc('hit_count')->get();

        if ($gaps->isEmpty()) {
            return redirect()
                ->route('admin.ai-assistant.knowledge-gaps.index')
                ->with('error', 'Those questions have already been closed.');
        }

        $result = $this->service->answer($gaps, [
            'title' => $data['title'],
            'title_ar' => $data['title_ar'] ?? null,
            'body' => $data['body'],
            'body_ar' => $data['body_ar'] ?? null,
            'category' => ($data['category'] ?? null) ?: null, // left empty, the AI files the answer by its subject
            'tags' => [],
            'audience' => $data['audience'],
            'audience_branch_id' => $data['audience'] === 'branch' ? $data['audience_branch_id'] : null,
            'audience_department_id' => $data['audience'] === 'department' ? $data['audience_department_id'] : null,
        ], Auth::id(), $indexer);

        $this->audit('ai_knowledge_gap_answered', $gaps, $result['article']->id);

        return redirect()
            ->route('admin.ai-assistant.knowledge-gaps.index')
            ->with('answered', [
                'article_id' => $result['article']->id,
                'title' => $result['article']->title,
                'indexed' => $result['indexed'],
                'scores' => $result['scores'],
            ]);
    }

    /** Not a question the company documentation should answer. It stays closed if asked again. */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate(['gap_ids' => 'required|array|min:1', 'gap_ids.*' => 'integer']);

        $gaps = AiKnowledgeGap::open()->whereIn('id', $data['gap_ids'])->get();
        $gaps->each(fn (AiKnowledgeGap $gap) => $gap->resolve(AiKnowledgeGap::DISMISSED, null, Auth::id()));

        $this->audit('ai_knowledge_gap_dismissed', $gaps);

        return redirect()
            ->route('admin.ai-assistant.knowledge-gaps.index')
            ->with('success', 'Dismissed. It stays dismissed if it is asked again.');
    }

    /** "Possibly answered by …" was right: close the question as covered by that article. */
    public function confirm(AiKnowledgeGap $aiKnowledgeGap): RedirectResponse
    {
        $article = AiKnowledgeArticle::where('is_published', true)->find($aiKnowledgeGap->best_article_id);

        abort_unless($article, 404);

        $gaps = $this->groupOf($aiKnowledgeGap);
        $gaps->each(fn (AiKnowledgeGap $gap) => $gap->resolve(AiKnowledgeGap::COVERED, $article->id, Auth::id()));

        $this->audit('ai_knowledge_gap_confirmed', $gaps, $article->id);

        return redirect()
            ->route('admin.ai-assistant.knowledge-gaps.index')
            ->with('success', "Closed as answered by “{$article->title}”.");
    }

    public function reopen(AiKnowledgeGap $aiKnowledgeGap): RedirectResponse
    {
        abort_if($aiKnowledgeGap->resolved_at === null, 404);

        $aiKnowledgeGap->reopen();
        $this->audit('ai_knowledge_gap_reopened', collect([$aiKnowledgeGap]));

        return redirect()
            ->route('admin.ai-assistant.knowledge-gaps.index')
            ->with('success', 'Opened again.');
    }

    /** For the answer form's Translate buttons: an FAQ answer, not a document, so it is short enough to wait for. */
    public function translate(Request $request, TextTranslator $translator): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:8000',
            'to' => ['required', Rule::in(['en', 'ar'])],
        ]);

        try {
            return response()->json(['text' => $translator->translate($data['text'], $data['to'])]);
        } catch (\Throwable $e) {
            Log::warning('AiKnowledgeGapController: translation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'The translation could not be made. Check the AI Assistant settings, or write both languages yourself.',
            ], 502);
        }
    }

    /** @return Collection<int, AiKnowledgeGap> the open wordings grouped with $gap, most asked first */
    private function groupOf(AiKnowledgeGap $gap): Collection
    {
        $key = $gap->group_id ?? $gap->id;

        return AiKnowledgeGap::open()
            ->where(fn ($query) => $query->where('group_id', $key)->orWhere('id', $key)->orWhere('id', $gap->id))
            ->orderByDesc('hit_count')
            ->get();
    }

    private function floor(): float
    {
        return (float) (AiSetting::get()->knowledge_match_threshold ?? KnowledgeRetriever::DEFAULT_RELEVANCE_FLOOR);
    }

    /** By hand: gaps are kept out of automatic auditing, being bumped by every unanswered question. */
    private function audit(string $action, Collection $gaps, ?int $articleId = null): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'AiKnowledgeGap',
                'model_id' => (int) $gaps->first()?->id,
                'action' => $action,
                'changes' => [
                    'questions' => $gaps->map(fn (AiKnowledgeGap $gap) => mb_substr((string) $gap->query_sample, 0, 200))->values()->all(),
                    'article_id' => $articleId,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging block the answer.
        }
    }
}
