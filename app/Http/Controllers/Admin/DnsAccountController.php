<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Services\Dns\GoDaddyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DnsAccountController extends Controller
{
    public function index()
    {
        $accounts = DnsAccount::with('creator')
            ->orderBy('label')
            ->get();

        return view('admin.dns.index', compact('accounts'));
    }

    public function create()
    {
        return view('admin.dns.form', ['account' => null]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'       => 'required|string|max:255',
            'api_key'     => 'required|string|max:500',
            'api_secret'  => 'required|string|max:500',
            'environment' => 'required|in:production,ote',
            'shopper_id'  => 'nullable|string|max:50',
            'notes'       => 'nullable|string',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['is_active']  = $request->boolean('is_active', true);
        $validated['created_by'] = Auth::id();
        $validated['updated_by'] = Auth::id();

        DnsAccount::create($validated);

        return redirect()->route('admin.network.dns.index')
            ->with('success', 'DNS account created successfully.');
    }

    public function edit(DnsAccount $account)
    {
        return view('admin.dns.form', compact('account'));
    }

    public function update(Request $request, DnsAccount $account)
    {
        $validated = $request->validate([
            'label'       => 'required|string|max:255',
            'api_key'     => 'nullable|string|max:500',
            'api_secret'  => 'nullable|string|max:500',
            'environment' => 'required|in:production,ote',
            'shopper_id'  => 'nullable|string|max:50',
            'notes'       => 'nullable|string',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['is_active']  = $request->boolean('is_active', true);
        $validated['updated_by'] = Auth::id();

        // Don't overwrite credentials with empty values
        if (empty($validated['api_key'])) unset($validated['api_key']);
        if (empty($validated['api_secret'])) unset($validated['api_secret']);

        $account->update($validated);

        return redirect()->route('admin.network.dns.index')
            ->with('success', "DNS account '{$account->label}' updated.");
    }

    public function destroy(DnsAccount $account)
    {
        $label = $account->label;
        $account->delete();

        return redirect()->route('admin.network.dns.index')
            ->with('success', "DNS account '{$label}' deleted.");
    }

    public function testConnection(DnsAccount $account)
    {
        $service = new GoDaddyService($account);
        $result  = $service->testConnection();

        $account->update([
            'last_tested_at'   => now(),
            'last_test_status' => $result['success'] ? 'success' : 'failed',
        ]);

        return response()->json($result);
    }
}
