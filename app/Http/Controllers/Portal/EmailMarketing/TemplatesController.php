<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\StoreTemplateRequest;
use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\View\View;

class TemplatesController extends Controller
{
    public function index(): View
    {
        $templates = EmailTemplate::latest()->paginate(25);

        return view('portal.email-marketing.templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.templates.edit', ['template' => new EmailTemplate]);
    }

    public function store(StoreTemplateRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $template = EmailTemplate::create($data);

        return redirect()->route('portal.marketing.templates.edit', $template)
            ->with('status', 'Template saved.');
    }

    public function edit(EmailTemplate $template): View
    {
        return view('portal.email-marketing.templates.edit', compact('template'));
    }

    public function update(StoreTemplateRequest $request, EmailTemplate $template)
    {
        $template->update($request->validated());
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
        return view('portal.email-marketing.templates.edit', compact('template'));
    }
}
