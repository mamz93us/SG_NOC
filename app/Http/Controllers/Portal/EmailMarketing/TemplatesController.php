<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\StoreTemplateRequest;
use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $showArchived = $request->boolean('archived');

        $templates = EmailTemplate::query()
            ->when(! $showArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('portal.email-marketing.templates.index', compact('templates', 'showArchived'));
    }

    public function create(Request $request): View
    {
        $template = new EmailTemplate(['editor_type' => 'unlayer']);

        return $this->editView($template);
    }

    public function store(StoreTemplateRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $data['editor_type'] = 'unlayer';
        $template = EmailTemplate::create($data);

        return redirect()->route('portal.marketing.templates.edit', $template)
            ->with('status', 'Template saved.');
    }

    public function edit(EmailTemplate $template): View
    {
        return $this->editView($template);
    }

    public function update(StoreTemplateRequest $request, EmailTemplate $template)
    {
        $data = $request->validated();
        unset($data['editor_type']); // editor_type is immutable; always Unlayer now
        $template->update($data);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'id' => $template->id]);
        }

        return redirect()->route('portal.marketing.templates.edit', $template)
            ->with('status', 'Template updated.');
    }

    public function destroy(EmailTemplate $template)
    {
        $template->delete();

        return redirect()->route('portal.marketing.templates.index')
            ->with('status', 'Template deleted.');
    }

    public function duplicate(EmailTemplate $template)
    {
        $copy = $template->replicate(['archived_at']);
        $copy->name = $template->name.' (copy)';
        $copy->save();

        return redirect()->route('portal.marketing.templates.edit', $copy)
            ->with('status', 'Template duplicated.');
    }

    public function archive(EmailTemplate $template)
    {
        $wasArchived = $template->archived_at !== null;
        $template->update(['archived_at' => $wasArchived ? null : now()]);

        return back()->with('status', $wasArchived ? 'Template restored.' : 'Template archived.');
    }

    public function show(EmailTemplate $template): View
    {
        return $this->editView($template);
    }

    /**
     * Public, signed-URL-protected preview of a template's rendered HTML.
     * Anyone with the URL can view (no auth) — useful for sharing draft
     * designs with stakeholders. Sign expires after 7 days.
     */
    public function publicPreview(Request $request, EmailTemplate $template)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Preview link is invalid or expired.');
        }

        $html = $template->rendered_html ?: '<!doctype html><html><body style="font-family:sans-serif;padding:40px;"><p>No content yet — this template has no rendered HTML.</p></body></html>';

        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'X-Robots-Tag'        => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'self' data: https:; img-src * data:; style-src 'unsafe-inline' *;",
        ]);
    }

    /**
     * Route to the Unlayer editor with the SAMIR icon library + custom fonts
     * loaded from the DB so marketing users can manage them via Icon Library /
     * Fonts pages and see them reflected here without code changes.
     */
    private function editView(EmailTemplate $template): View
    {
        $icons = \App\Models\EmailMarketing\EmailMarketingIcon::query()
            ->orderBy('sort_order')->orderBy('label')->get();
        $fonts = \App\Models\EmailMarketing\EmailMarketingFont::query()
            ->orderBy('sort_order')->orderBy('label')->get();

        $viewName = 'portal.email-marketing.templates.edit';

        return view($viewName, compact('template', 'icons', 'fonts'));
    }
}
