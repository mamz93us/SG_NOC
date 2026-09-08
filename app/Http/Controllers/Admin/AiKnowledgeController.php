<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiKnowledgeArticle;
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
        $this->indexer->indexArticle($article);

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Article created.');
    }

    public function update(Request $request, AiKnowledgeArticle $aiKnowledgeArticle): RedirectResponse
    {
        $aiKnowledgeArticle->update($this->validated($request));
        $this->indexer->indexArticle($aiKnowledgeArticle);

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Article updated.');
    }

    public function destroy(AiKnowledgeArticle $aiKnowledgeArticle): RedirectResponse
    {
        $aiKnowledgeArticle->delete(); // chunks cascade-delete with it

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', 'Article deleted.');
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
