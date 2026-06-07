<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailList;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListsController extends Controller
{
    public function index(): View
    {
        $lists = EmailList::withCount('subscribers')->orderBy('name')->paginate(25);

        return view('portal.email-marketing.lists.index', compact('lists'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.lists.create', ['list' => new EmailList]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $list = EmailList::create($data);

        return redirect()->route('portal.marketing.lists.show', $list)
            ->with('status', 'List created.');
    }

    public function show(EmailList $list): View
    {
        $list->loadCount('subscribers');
        $subscribers = $list->subscribers()->paginate(50);

        return view('portal.email-marketing.lists.show', compact('list', 'subscribers'));
    }

    public function edit(EmailList $list): View
    {
        return view('portal.email-marketing.lists.create', ['list' => $list]);
    }

    public function update(Request $request, EmailList $list)
    {
        $list->update($this->validated($request));

        return redirect()->route('portal.marketing.lists.show', $list)
            ->with('status', 'List updated.');
    }

    public function destroy(EmailList $list)
    {
        if ($list->isDynamic()) {
            return redirect()->route('portal.marketing.lists.show', $list)
                ->with('error', 'Dynamic lists (auto-synced from employees) cannot be deleted from the portal.');
        }

        $list->delete();

        return redirect()->route('portal.marketing.lists.index')
            ->with('status', 'List deleted.');
    }

    /**
     * Streams every subscriber on this list as a CSV. Chunked so it stays
     * memory-flat on huge lists. UTF-8 BOM so Excel opens it cleanly.
     */
    public function export(EmailList $list): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'subscribers-'.\Illuminate\Support\Str::slug($list->name).'-'.now()->format('Ymd-His').'.csv';

        \App\Models\ActivityLog::create([
            'model_type' => 'EmailList',
            'model_id' => $list->id,
            'action' => 'subscribers_exported',
            'changes' => ['list' => $list->name],
            'user_id' => auth()->id(),
        ]);

        return response()->streamDownload(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'email', 'first_name', 'last_name', 'status',
                'subscribed_at', 'unsubscribed_at', 'source', 'last_bounce_type',
            ]);

            $list->subscribers()->orderBy('email_subscribers.id')->chunk(500, function ($subs) use ($out) {
                foreach ($subs as $s) {
                    fputcsv($out, [
                        $s->email,
                        $s->first_name,
                        $s->last_name,
                        $s->status,
                        optional($s->pivot->subscribed_at)?->toDateTimeString(),
                        optional($s->pivot->unsubscribed_at)?->toDateTimeString(),
                        $s->source,
                        $s->last_bounce_type,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'double_opt_in' => ['nullable', 'boolean'],
            'auto_domain' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'default_from_email' => ['nullable', 'email', 'max:191'],
            'default_from_name' => ['nullable', 'string', 'max:191'],
            'default_reply_to' => ['nullable', 'email', 'max:191'],
        ]) + ['double_opt_in' => $request->boolean('double_opt_in')];
    }
}
