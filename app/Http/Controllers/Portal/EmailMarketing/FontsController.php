<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailMarketingFont;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FontsController extends Controller
{
    public function index(): View
    {
        $fonts = EmailMarketingFont::orderBy('sort_order')->orderBy('label')->paginate(50);

        return view('portal.email-marketing.fonts.index', compact('fonts'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.fonts.edit', ['font' => new EmailMarketingFont(['source' => 'google'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['is_default'] = $request->boolean('is_default');
        $data['created_by'] = $request->user()->id;
        EmailMarketingFont::create($data);

        return redirect()->route('portal.marketing.fonts.index')->with('status', 'Font added.');
    }

    public function edit(EmailMarketingFont $font): View
    {
        return view('portal.email-marketing.fonts.edit', compact('font'));
    }

    public function update(Request $request, EmailMarketingFont $font)
    {
        $data = $this->validated($request);
        $data['is_default'] = $request->boolean('is_default');
        $font->update($data);

        return redirect()->route('portal.marketing.fonts.index')->with('status', 'Font updated.');
    }

    public function destroy(EmailMarketingFont $font)
    {
        $font->delete();

        return redirect()->route('portal.marketing.fonts.index')->with('status', 'Font deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'family'     => ['required', 'string', 'max:191'],
            'source'     => ['nullable', \Illuminate\Validation\Rule::in(['google', 'custom'])],
            'url'        => ['nullable', 'url', 'max:500'],
            'sort_order' => ['nullable', 'integer'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }
}
