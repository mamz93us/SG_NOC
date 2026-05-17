<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailTag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagsController extends Controller
{
    public function index(): View
    {
        $tags = EmailTag::withCount('subscribers')->orderBy('name')->paginate(50);

        return view('portal.email-marketing.tags.index', compact('tags'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.tags.edit', ['tag' => new EmailTag]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        EmailTag::create($data);

        return redirect()->route('portal.marketing.tags.index')->with('status', 'Tag created.');
    }

    public function edit(EmailTag $tag): View
    {
        return view('portal.email-marketing.tags.edit', compact('tag'));
    }

    public function update(Request $request, EmailTag $tag)
    {
        $tag->update($this->validated($request, $tag->id));

        return redirect()->route('portal.marketing.tags.index')->with('status', 'Tag updated.');
    }

    public function destroy(EmailTag $tag)
    {
        $tag->delete();

        return redirect()->route('portal.marketing.tags.index')->with('status', 'Tag deleted.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('email_tags', 'name')->ignore($ignoreId),
            ],
            'color' => ['nullable', 'string', 'max:7'],
        ]);
    }
}
