<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiKnowledgeArticle;
use App\Models\AiWebSource;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Ai\Web\HostGuard;
use App\Services\Ai\Web\UrlTools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * AI Assistant Knowledge ▸ Websites: the sites the assistant may read into
 * its knowledge base.
 *
 * Saving only records what is allowed; reading is `ai:crawl-websites`, in the
 * background. The one network call made here is resolving the host, so that a
 * name that does not resolve from the NOC, or resolves inside the company
 * without internal addresses allowed, is refused while the admin is still on
 * the form.
 */
class AiWebSourceController extends Controller
{
    public function __construct(private HostGuard $guard) {}

    public function index(): View
    {
        return view('admin.ai-knowledge.websites.index', [
            'sources' => AiWebSource::query()->orderBy('name')->get(),
            'newSource' => new AiWebSource(['audience' => 'all', 'publish' => true]),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $source = AiWebSource::create($this->validated($request) + [
            'status' => AiWebSource::QUEUED,
            'next_crawl_at' => now(),
            'created_by' => Auth::id(),
        ]);

        $this->audit('ai_web_source_added', $source);

        return redirect()
            ->route('admin.ai-assistant.knowledge.websites.show', $source)
            ->with('success', 'Added. Reading starts within five minutes, and the pages appear here as they are read.');
    }

    public function show(AiWebSource $aiWebSource): View
    {
        return view('admin.ai-knowledge.websites.show', [
            'source' => $aiWebSource,
            'pages' => $aiWebSource->pages()
                ->with('article:id,title,is_published')
                ->orderBy('depth')
                ->orderBy('url')
                ->paginate(50),
            'counts' => $aiWebSource->pages()
                ->selectRaw('status, COUNT(*) AS pages')
                ->groupBy('status')
                ->pluck('pages', 'status'),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, AiWebSource $aiWebSource): RedirectResponse
    {
        $aiWebSource->update($this->validated($request));
        $this->audit('ai_web_source_changed', $aiWebSource);

        return redirect()
            ->route('admin.ai-assistant.knowledge.websites.show', $aiWebSource)
            ->with('success', 'Saved. The changes apply from the next read; use Read now to apply them today.');
    }

    public function crawl(AiWebSource $aiWebSource): RedirectResponse
    {
        if ($aiWebSource->status !== AiWebSource::CRAWLING) {
            $aiWebSource->forceFill(['status' => AiWebSource::QUEUED, 'next_crawl_at' => now(), 'error' => null])->save();
        }

        return redirect()
            ->route('admin.ai-assistant.knowledge.websites.show', $aiWebSource)
            ->with('success', 'Queued. Reading starts within five minutes.');
    }

    /** The website and every article read from it. */
    public function destroy(AiWebSource $aiWebSource): RedirectResponse
    {
        $articleIds = $aiWebSource->pages()->whereNotNull('article_id')->pluck('article_id');

        AiKnowledgeArticle::whereIn('id', $articleIds)->get()->each->delete(); // chunks cascade
        $aiWebSource->delete(); // pages cascade

        $this->audit('ai_web_source_deleted', $aiWebSource, ['articles_deleted' => $articleIds->count()]);

        return redirect()
            ->route('admin.ai-assistant.knowledge.websites.index')
            ->with('success', "“{$aiWebSource->name}” and the {$articleIds->count()} articles read from it are deleted.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'start_url' => ['required', 'url:http,https', 'max:2048'],
            'scope_url' => ['nullable', 'url:http,https', 'max:2048'],
            'max_pages' => 'required|integer|min:1|max:500',
            'max_depth' => 'required|integer|min:0|max:5',
            'refresh_days' => 'required|integer|min:0|max:90',
            'category' => 'nullable|string|max:50',
            'audience' => ['required', Rule::in(['all', 'branch', 'department'])],
            'audience_branch_id' => 'nullable|required_if:audience,branch|integer|exists:branches,id',
            'audience_department_id' => 'nullable|required_if:audience,department|integer|exists:departments,id',
        ], [], [
            'start_url' => 'start address',
            'scope_url' => 'part of the site to follow',
            'max_pages' => 'most pages',
            'max_depth' => 'clicks from the start',
            'refresh_days' => 'days between reads',
            'audience_branch_id' => 'branch',
            'audience_department_id' => 'department',
        ]);

        $start = UrlTools::normalize($data['start_url']);
        $scope = ($data['scope_url'] ?? null) ? UrlTools::normalize($data['scope_url']) : ($start ? UrlTools::folder($start) : null);

        if ($start === null || $scope === null) {
            throw ValidationException::withMessages(['start_url' => 'That is not an address that can be read.']);
        }

        if (! UrlTools::inScope($start, $scope)) {
            throw ValidationException::withMessages(['scope_url' => 'The start address must be inside the part of the site to follow.']);
        }

        $allowInternal = $request->boolean('allow_internal');

        try {
            $this->guard->check((string) parse_url($start, PHP_URL_HOST), $allowInternal);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['start_url' => $e->getMessage()]);
        }

        return [
            'name' => $data['name'],
            'start_url' => $start,
            'scope_url' => $scope,
            'max_pages' => (int) $data['max_pages'],
            'max_depth' => (int) $data['max_depth'],
            'refresh_days' => (int) $data['refresh_days'],
            'category' => $data['category'] ?? null,
            'audience' => $data['audience'],
            'audience_branch_id' => $data['audience'] === 'branch' ? $data['audience_branch_id'] : null,
            'audience_department_id' => $data['audience'] === 'department' ? $data['audience_department_id'] : null,
            'publish' => $request->boolean('publish'),
            'allow_internal' => $allowInternal,
        ];
    }

    /** By hand: websites and their pages are kept out of automatic auditing, being rewritten on every read. */
    private function audit(string $action, AiWebSource $source, array $extra = []): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'AiWebSource',
                'model_id' => $source->id,
                'action' => $action,
                'changes' => [
                    'name' => $source->name,
                    'start_url' => $source->start_url,
                    'scope_url' => $source->scope_url,
                    'allow_internal' => (bool) $source->allow_internal,
                    'publish' => (bool) $source->publish,
                ] + $extra,
                'user_id' => Auth::id(),
            ]);
        } catch (Throwable) {
            // Never let audit logging block the change.
        }
    }
}
