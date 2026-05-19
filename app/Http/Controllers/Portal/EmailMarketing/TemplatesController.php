<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\StoreTemplateRequest;
use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplatesController extends Controller
{
    public function index(): View
    {
        $templates = EmailTemplate::latest()->paginate(25);

        return view('portal.email-marketing.templates.index', compact('templates'));
    }

    public function create(Request $request): View
    {
        // ?editor=grapesjs picks the MJML editor; default is Unlayer.
        $editor = $request->query('editor', 'unlayer');
        $template = new EmailTemplate(['editor_type' => in_array($editor, ['unlayer', 'grapesjs']) ? $editor : 'unlayer']);

        return $this->editView($template);
    }

    public function store(StoreTemplateRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $data['editor_type'] = $data['editor_type'] ?? 'unlayer';
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
        // Don't allow flipping editor_type after creation — design_json shape differs.
        unset($data['editor_type']);
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

    public function show(EmailTemplate $template): View
    {
        return $this->editView($template);
    }

    /**
     * Route to the editor-specific view based on the template's editor_type.
     */
    private function editView(EmailTemplate $template): View
    {
        $viewName = $template->editor_type === 'grapesjs'
            ? 'portal.email-marketing.templates.edit-grapesjs'
            : 'portal.email-marketing.templates.edit';

        return view($viewName, compact('template'));
    }
}
