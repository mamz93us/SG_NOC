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
