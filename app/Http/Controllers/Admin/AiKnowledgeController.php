<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReindexAiKnowledgeJob;
use App\Models\AiKnowledgeArticle;
use App\Models\AiSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → AI Assistant Knowledge.
 *
 * Authoring for the articles the AI IT Assistant searches and cites. Every
 * save re-indexes the article (chunk + embed) so the assistant's answers stay
 * in step with what is published, without a separate "reindex" step to
 * remember.
 */
class AiKnowledgeController extends Controller
{
    public function __construct(private KnowledgeIndexer $indexer) {}

    public function index(Request $request): View
    {
        $category = trim((string) $request->query('category', ''));

        return view('admin.ai-knowledge.index', [
            'articles' => AiKnowledgeArticle::with(['branch', 'department'])
                ->when($category !== '', fn ($q) => $q->where('category', $category))
                ->withCount('chunks')
                ->orderByDesc('is_published')
                ->orderBy('title')
                ->paginate(30)
                ->withQueryString(),
            'category' => $category,
            'aiSettings' => AiSetting::get(),
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

        return ['success', "Article {$verb}."];
    }

    public function destroy(AiKnowledgeArticle $aiKnowledgeArticle): RedirectResponse
    {
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
        ReindexAiKnowledgeJob::dispatch(Auth::id());

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Reindexing all articles in the background — refresh in a minute or two to see updated chunk counts.');
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
