<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AllowedDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AllowedDomainController extends Controller
{
    public function index()
    {
        $this->authorize('manage-allowed-domains');
        $domains = AllowedDomain::orderBy('domain')->get();
        return view('admin.settings.domains', compact('domains'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-allowed-domains');

        $validated = $request->validate([
            'domain'      => 'required|string|max:100|unique:allowed_domains,domain',
            'description' => 'nullable|string|max:200',
            'is_primary'  => 'boolean',
        ]);

        // If marking as primary, clear existing primary first
        if ($request->boolean('is_primary')) {
            AllowedDomain::where('is_primary', 1)->update(['is_primary' => 0]);
        }

        $created = AllowedDomain::create([
            'domain'      => strtolower(trim($validated['domain'])),
            'description' => $validated['description'] ?? null,
            'is_primary'  => $request->boolean('is_primary'),
        ]);

        AllowedDomain::clearCache();

        ActivityLog::create([
            'model_type' => AllowedDomain::class,
            'model_id'   => $created->id,
            'action'     => 'allowed_domain_created',
            'changes'    => $created->toArray(),
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Domain \"{$validated['domain']}\" added.");
    }

    public function destroy(AllowedDomain $allowedDomain)
    {
        $this->authorize('manage-allowed-domains');
        $domain = $allowedDomain->domain;
        $snapshot = $allowedDomain->toArray();
        $allowedDomain->delete();
        AllowedDomain::clearCache();

        ActivityLog::create([
            'model_type' => AllowedDomain::class,
            'model_id'   => $allowedDomain->id,
            'action'     => 'allowed_domain_deleted',
            'changes'    => $snapshot,
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Domain \"{$domain}\" removed.");
    }

    public function setPrimary(AllowedDomain $allowedDomain)
    {
        $this->authorize('manage-allowed-domains');

        $previousPrimary = AllowedDomain::where('is_primary', 1)->pluck('domain')->all();
        AllowedDomain::where('is_primary', 1)->update(['is_primary' => 0]);
        $allowedDomain->update(['is_primary' => 1]);
        AllowedDomain::clearCache();

        ActivityLog::create([
            'model_type' => AllowedDomain::class,
            'model_id'   => $allowedDomain->id,
            'action'     => 'allowed_domain_set_primary',
            'changes'    => ['new_primary' => $allowedDomain->domain, 'previous_primary' => $previousPrimary],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "\"{$allowedDomain->domain}\" set as primary domain.");
    }
}
