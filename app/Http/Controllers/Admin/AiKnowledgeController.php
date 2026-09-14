<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReindexAiKnowledgeJob;
use App\Models\ActivityLog;
use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeImport;
use App\Models\AiSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Ai\ArticleClassifier;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Admin → AI Assistant Knowledge.
 *
 * Authoring for the articles the AI IT Assistant searches and cites. Every
 * save re-indexes the article (chunk + embed) so the assistant's answers stay
 * in step with what is published, without a separate "reindex" step to
 * remember. Articles can also be imported from PDFs — see
 * AiKnowledgeImportController — and their category and tags left to the AI —
 * see ArticleClassifier.
 */
class AiKnowledgeController extends Controller
{
    public function __construct(private KnowledgeIndexer $indexer) {}

    public function index(Request $request): View
    {
        $category = trim((string) $request->query('category', ''));

        return view('admin.ai-knowledge.index', [
            'articles' => AiKnowledgeArticle::with(['branch', 'department', 'import:id,article_id,file_name', 'webPage:id,article_id,url'])
                ->when($category !== '', fn ($q) => $q->where('category', $category))
                ->withCount('chunks')
                ->orderByDesc('is_published')
                ->orderBy('title')
                ->paginate(30)
                ->withQueryString(),
            'category' => $category,
            'aiSettings' => AiSetting::get(),
            // Everything still being read or that failed, and what finished this week.
            'imports' => AiKnowledgeImport::with('article:id,title,is_published')
                ->where(fn ($q) => $q->where('status', '!=', AiKnowledgeImport::DONE)
                    ->orWhere('finished_at', '>=', now()->subDays(7)))
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            // Category and tags by AI: what waits for ai:classify-articles, and what lacks either.
            'classifyQueued' => AiKnowledgeArticle::whereNotNull('ai_classify')->count(),
            'classifyMissing' => $this->unfiledIds()->count(),
            'articleTotal' => AiKnowledgeArticle::count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.ai-knowledge.form', [
            'article' => new AiKnowledgeArticle(['audience' => 'all']),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function edit(AiKnowledgeArticle $aiKnowledgeArticle): View
    {
        return view('admin.ai-knowledge.form', [
            'article' => $aiKnowledgeArticle,
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = Auth::id();

        $article = AiKnowledgeArticle::create($data);
        $indexed = $this->indexer->indexArticle($article);

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with(...$this->saveFlash('created', $article, $indexed));
    }

    public function update(Request $request, AiKnowledgeArticle $aiKnowledgeArticle): RedirectResponse
    {
        $aiKnowledgeArticle->update($this->validated($request));
        $indexed = $this->indexer->indexArticle($aiKnowledgeArticle);

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with(...$this->saveFlash('updated', $aiKnowledgeArticle, $indexed));
    }

    /**
     * @return array{0:string,1:string} a single ['key' => 'message'] pair for
     *                                  redirect()->with(...) — success unless
     *                                  the article is published and embedding
     *                                  just failed, which is the "the AI
     *                                  doesn't work" symptom this surfaces
     *                                  immediately instead of a silent log line.
     */
    private function saveFlash(string $verb, AiKnowledgeArticle $article, bool $indexed): array
    {
        if ($article->is_published && ! $indexed) {
            return ['error', "Article {$verb}, but indexing failed — check the Azure OpenAI settings and use Reindex All once fixed."];
        }

        return ['success', "Article {$verb}.".($article->ai_classify ? ' The AI fills in its category and tags in a minute or two.' : '')];
    }

    public function destroy(AiKnowledgeArticle $aiKnowledgeArticle): RedirectResponse
    {
        // An imported article takes its PDF with it. The foreign key alone would
        // only null the import's article_id and leave the file behind.
        $aiKnowledgeArticle->import?->delete();

        $aiKnowledgeArticle->delete(); // chunks cascade-delete with it

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Article deleted.');
    }

    /**
     * "Reindex All" — re-chunks and re-embeds every published article.
     *
     * Recovers articles whose embedding failed silently (e.g. saved before
     * Azure OpenAI was configured, or during an outage) without having to
     * open and re-save each one. Queued: see ReindexAiKnowledgeJob.
     */
    public function reindexAll(): RedirectResponse
    {
        AiSetting::get()->update([
            'last_reindex_started_at' => now(),
            'last_reindex_finished_at' => null,
            'last_reindex_result' => null,
        ]);

        ReindexAiKnowledgeJob::dispatch(Auth::id());

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Reindexing all articles in the background — this page will show when it finishes.');
    }

    /** Polled by the "Reindex All" status banner so the page can tell you when it's done. */
    public function reindexStatus(): JsonResponse
    {
        $settings = AiSetting::get();

        return response()->json([
            'running' => $settings->reindexRunning(),
            'started_at' => $settings->last_reindex_started_at?->toIso8601String(),
            'finished_at' => $settings->last_reindex_finished_at?->toIso8601String(),
            'result' => $settings->last_reindex_result,
        ]);
    }

    /**
     * Suggest with AI on the article form: a category and tags for what is typed
     * there. Nothing is saved; the admin checks them and saves the form.
     */
    public function classifySuggest(Request $request, ArticleClassifier $classifier): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'body' => 'required|string',
        ], [
            'title.required' => 'Write the title first.',
            'body.required' => 'Write the body first.',
        ]);

        try {
            return response()->json($classifier->classify($data['title'], $data['title_ar'] ?? null, $data['body']));
        } catch (Throwable $e) {
            Log::warning('AI knowledge: suggesting a category and tags failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'The AI could not suggest a category and tags: '.$e->getMessage()], 502);
        }
    }

    /** The AI button on an article's row: reads the article and assigns both, replacing what was there. */
    public function classify(AiKnowledgeArticle $aiKnowledgeArticle, ArticleClassifier $classifier): RedirectResponse
    {
        try {
            $assigned = $classifier->assign($aiKnowledgeArticle, AiKnowledgeArticle::CLASSIFY_REPLACE);
        } catch (Throwable $e) {
            $aiKnowledgeArticle->forceFill(['ai_classify_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return back()->with('error', "The AI could not file “{$aiKnowledgeArticle->title}”: {$e->getMessage()}");
        }

        return back()->with('success', "“{$aiKnowledgeArticle->title}” is filed under {$assigned['category']} and tagged ".implode(', ', $assigned['tags']).'.');
    }

    /**
     * Category and tags by AI for many articles, queued for ai:classify-articles.
     * "missing" takes the articles without a category or tags and fills in only
     * what is empty; "replace" takes every article and chooses both again.
     */
    public function classifyAll(Request $request): RedirectResponse
    {
        $mode = $request->validate([
            'mode' => ['required', Rule::in([AiKnowledgeArticle::CLASSIFY_MISSING, AiKnowledgeArticle::CLASSIFY_REPLACE])],
        ])['mode'];

        $ids = $mode === AiKnowledgeArticle::CLASSIFY_MISSING ? $this->unfiledIds() : AiKnowledgeArticle::query()->pluck('id');

        // As a query rather than model by model: being queued is not an edit, and
        // should neither touch updated_at nor write an audit line per article.
        foreach ($ids->chunk(500) as $chunk) {
            AiKnowledgeArticle::query()->whereIn('id', $chunk->all())->toBase()
                ->update(['ai_classify' => $mode, 'ai_classify_error' => null]);
        }

        try {
            ActivityLog::create([
                'model_type' => 'AiKnowledgeArticle',
                'model_id' => 0, // many articles, as ReindexAiKnowledgeJob logs it
                'action' => 'ai_knowledge_classify_queued',
                'changes' => ['mode' => $mode, 'articles' => $ids->count()],
                'user_id' => Auth::id(),
            ]);
        } catch (Throwable) {
            // Never let audit logging block the change.
        }

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', $ids->isEmpty()
                ? 'Every article already has a category and tags.'
                : $ids->count().' '.Str::plural('article', $ids->count()).' queued. The AI reads each one and fills in its category and tags over the next few minutes; this page shows the progress.');
    }

    /** Polled by the progress banner while articles wait for the AI. */
    public function classifyStatus(): JsonResponse
    {
        return response()->json(['queued' => AiKnowledgeArticle::whereNotNull('ai_classify')->count()]);
    }

    /** @return Collection<int, int> the articles without a category, or without tags */
    private function unfiledIds(): Collection
    {
        return AiKnowledgeArticle::query()
            ->get(['id', 'category', 'tags'])
            ->filter(fn (AiKnowledgeArticle $article) => blank($article->category) || empty($article->tags))
            ->pluck('id');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'body' => 'required|string',
            'body_ar' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'tags' => 'nullable|string|max:500',
            'audience' => ['required', Rule::in(['all', 'branch', 'department'])],
            'audience_branch_id' => 'nullable|integer|exists:branches,id',
            'audience_department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $data['is_published'] = $request->boolean('is_published');

        $data['tags'] = $data['tags']
            ? array_values(array_filter(array_map('trim', explode(',', $data['tags']))))
            : [];

        if ($data['audience'] !== 'branch') {
            $data['audience_branch_id'] = null;
        }
        if ($data['audience'] !== 'department') {
            $data['audience_department_id'] = null;
        }

        return $data;
    }
}
