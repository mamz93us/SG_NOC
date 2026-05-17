<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailSegment;
use App\Models\EmailMarketing\EmailTag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SegmentsController extends Controller
{
    public function index(): View
    {
        $segments = EmailSegment::orderBy('name')->paginate(25);

        return view('portal.email-marketing.segments.index', compact('segments'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.segments.edit', [
            'segment' => new EmailSegment(['rules' => ['operator' => 'AND', 'conditions' => []]]),
            'tags' => EmailTag::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        EmailSegment::create($data);

        return redirect()->route('portal.marketing.segments.index')->with('status', 'Segment created.');
    }

    public function edit(EmailSegment $segment): View
    {
        return view('portal.email-marketing.segments.edit', [
            'segment' => $segment,
            'tags' => EmailTag::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, EmailSegment $segment)
    {
        $segment->update($this->validated($request));

        return redirect()->route('portal.marketing.segments.index')->with('status', 'Segment updated.');
    }

    public function destroy(EmailSegment $segment)
    {
        $segment->delete();

        return redirect()->route('portal.marketing.segments.index')->with('status', 'Segment deleted.');
    }

    public function show(EmailSegment $segment): View
    {
        return view('portal.email-marketing.segments.edit', [
            'segment' => $segment,
            'tags' => EmailTag::orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['nullable', 'string'],
        ]);

        // Rules come in as JSON string from the textarea / hidden input.
        $decoded = json_decode((string) ($data['rules'] ?? ''), true);
        $data['rules'] = is_array($decoded)
            ? $decoded
            : ['operator' => 'AND', 'conditions' => []];

        return $data;
    }
}
