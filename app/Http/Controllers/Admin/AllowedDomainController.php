<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use Illuminate\Http\Request;

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

        AllowedDomain::create([
            'domain'      => strtolower(trim($validated['domain'])),
            'description' => $validated['description'] ?? null,
            'is_primary'  => $request->boolean('is_primary'),
        ]);

        AllowedDomain::clearCache();

        return back()->with('success', "Domain \"{$validated['domain']}\" added.");
    }

    public function destroy(AllowedDomain $allowedDomain)
    {
        $this->authorize('manage-allowed-domains');
        $domain = $allowedDomain->domain;
        $allowedDomain->delete();
        AllowedDomain::clearCache();
        return back()->with('success', "Domain \"{$domain}\" removed.");
    }

    public function setPrimary(AllowedDomain $allowedDomain)
    {
        $this->authorize('manage-allowed-domains');
        AllowedDomain::where('is_primary', 1)->update(['is_primary' => 0]);
        $allowedDomain->update(['is_primary' => 1]);
        AllowedDomain::clearCache();
        return back()->with('success', "\"{$allowedDomain->domain}\" set as primary domain.");
    }
}
