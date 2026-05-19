<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailMarketingIcon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IconsController extends Controller
{
    public function index(): View
    {
        $icons = EmailMarketingIcon::orderBy('sort_order')->orderBy('label')->paginate(50);

        return view('portal.email-marketing.icons.index', compact('icons'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.icons.edit', ['icon' => new EmailMarketingIcon()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        EmailMarketingIcon::create($data);

        return redirect()->route('portal.marketing.icons.index')->with('status', 'Icon added.');
    }

    public function edit(EmailMarketingIcon $icon): View
    {
        return view('portal.email-marketing.icons.edit', compact('icon'));
    }

    public function update(Request $request, EmailMarketingIcon $icon)
    {
        $icon->update($this->validated($request, $icon->id));

        return redirect()->route('portal.marketing.icons.index')->with('status', 'Icon updated.');
    }

    public function destroy(EmailMarketingIcon $icon)
    {
        $icon->delete();

        return redirect()->route('portal.marketing.icons.index')->with('status', 'Icon deleted.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/',
                \Illuminate\Validation\Rule::unique('email_marketing_icons', 'name')->ignore($ignoreId)],
            'label'         => ['required', 'string', 'max:100'],
            'svg_path'      => ['required', 'string', 'max:5000'],
            'default_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_size'  => ['nullable', 'integer', 'min:12', 'max:256'],
            'sort_order'    => ['nullable', 'integer'],
        ]);
    }
}
