<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\UcmServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UcmServerController extends Controller
{
    // Accepts URLs with ports like https://host:8089
    protected array $urlRules = [
        'name'         => 'required|string|max:100',
        'url'          => ['required', 'string', 'max:255', 'regex:/^https?:\/\/.+/'],
        'cloud_domain' => 'nullable|string|max:255',
        'api_username' => 'required|string|max:100',
        'api_password' => 'required|string|max:255',
    ];

    public function store(Request $request)
    {
        $data = $request->validate($this->urlRules);

        $ucm = UcmServer::create($data);

        ActivityLog::create([
            'model_type' => 'UcmServer',
            'model_id'   => $ucm->id,
            'action'     => 'created',
            'changes'    => ['name' => $ucm->name, 'url' => $ucm->url, 'api_username' => $ucm->api_username],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'UCM Server added successfully.');
    }

    public function update(Request $request, UcmServer $ucmServer)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:100',
            'url'          => ['required', 'string', 'max:255', 'regex:/^https?:\/\/.+/'],
            'cloud_domain' => 'nullable|string|max:255',
            'api_username' => 'required|string|max:100',
            'api_password' => 'nullable|string|max:255',
        ]);

        $old = $ucmServer->only(['name', 'url', 'api_username']);

        // Keep old password if field is empty
        if (empty($data['api_password'])) {
            unset($data['api_password']);
        }

        $ucmServer->update($data);

        ActivityLog::create([
            'model_type' => 'UcmServer',
            'model_id'   => $ucmServer->id,
            'action'     => 'updated',
            'changes'    => [
                'old' => $old,
                'new' => $ucmServer->fresh()->only(['name', 'url', 'api_username']),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'UCM Server updated successfully.');
    }

    public function destroy(UcmServer $ucmServer)
    {
        $snapshot = $ucmServer->only(['id', 'name', 'url', 'api_username']);

        $ucmServer->delete();

        ActivityLog::create([
            'model_type' => 'UcmServer',
            'model_id'   => $snapshot['id'],
            'action'     => 'deleted',
            'changes'    => $snapshot,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'UCM Server deleted.');
    }

    public function toggleActive(UcmServer $ucmServer)
    {
        $newState = !$ucmServer->is_active;
        $ucmServer->update(['is_active' => $newState]);

        ActivityLog::create([
            'model_type' => 'UcmServer',
            'model_id'   => $ucmServer->id,
            'action'     => 'updated',
            'changes'    => [
                'name'      => $ucmServer->name,
                'is_active' => ['old' => !$newState, 'new' => $newState],
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'UCM Server status updated.');
    }
}
