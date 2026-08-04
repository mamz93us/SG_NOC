<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BusinessApp;
use App\Models\IdentityGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Business App Accounts.
 *
 * Per app: who gets emailed to create the account, and which Azure security
 * group the employee joins. One table, saved in a single submit.
 */
class BusinessAppController extends Controller
{
    public function index(): View
    {
        $apps = BusinessApp::with('identityGroup')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Security groups only — these grant access, so offering distribution
        // lists here would just be a way to make a mapping that does nothing.
        $groups = IdentityGroup::where('security_enabled', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name']);

        return view('admin.business_apps.index', compact('apps', 'groups'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'apps' => 'required|array',
            'apps.*.request_emails' => 'nullable|string|max:500',
            'apps.*.identity_group_id' => 'nullable|integer|exists:identity_groups,id',
            'apps.*.is_active' => 'nullable|boolean',
        ]);

        $before = BusinessApp::all()
            ->mapWithKeys(fn ($a) => [$a->key => $a->request_emails])
            ->all();

        foreach ($validated['apps'] as $id => $fields) {
            $app = BusinessApp::find($id);
            if (! $app) {
                continue;
            }

            // Validate each address individually rather than trusting the blob —
            // one typo silently dropping a recipient is the failure mode here.
            $emails = collect(preg_split('/[,;\s]+/', (string) ($fields['request_emails'] ?? '')))
                ->map(fn ($e) => trim($e))
                ->filter()
                ->values();

            $bad = $emails->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            if ($bad->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    "“{$bad->first()}” is not a valid email address ({$app->name})."
                );
            }

            $app->update([
                'request_emails' => $emails->implode(', ') ?: null,
                'identity_group_id' => $fields['identity_group_id'] ?? null,
                'is_active' => (bool) ($fields['is_active'] ?? false),
            ]);
        }

        ActivityLog::create([
            'model_type' => BusinessApp::class,
            'model_id' => 0,
            'action' => 'business_apps_updated',
            'changes' => [
                'before' => $before,
                'after' => BusinessApp::all()->mapWithKeys(fn ($a) => [$a->key => $a->request_emails])->all(),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.business-apps.index')
            ->with('success', 'Business app settings updated.');
    }
}
