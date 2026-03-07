<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialAccessLog;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CredentialController extends Controller
{
    public function index(Request $request)
    {
        $query = Credential::with(['device.branch', 'creator'])
                    ->orderBy('category')
                    ->orderBy('title');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('device')) {
            $query->where('device_id', $request->device);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title',    'like', "%{$s}%")
                  ->orWhere('username','like', "%{$s}%");
            });
        }

        $credentials = $query->paginate(50)->withQueryString();
        $devices     = Device::orderBy('name')->get(['id', 'name', 'type']);
        $categories  = ['admin', 'api', 'snmp', 'user', 'service', 'other'];

        return view('admin.credentials.index', compact('credentials', 'devices', 'categories'));
    }

    public function create()
    {
        $devices    = Device::orderBy('name')->get(['id', 'name', 'type', 'branch_id']);
        $categories = ['admin', 'api', 'snmp', 'user', 'service', 'other'];
        return view('admin.credentials.form', compact('devices', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'     => 'required|string|max:255',
            'username'  => 'nullable|string|max:255',
            'password'  => 'required|string',
            'url'       => 'nullable|url|max:500',
            'notes'     => 'nullable|string',
            'device_id' => 'nullable|exists:devices,id',
            'category'  => 'required|in:admin,api,snmp,user,service,other',
        ]);

        $credential = Credential::create(array_merge($data, [
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]));

        $credential->logAccess('created', Auth::id(), $request->ip());

        ActivityLog::create([
            'model_type' => 'Credential',
            'model_id'   => $credential->id,
            'action'     => 'created',
            'changes'    => ['title' => $credential->title, 'category' => $credential->category],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.credentials.index')
                         ->with('success', "Credential \"{$credential->title}\" created.");
    }

    public function edit(Credential $credential)
    {
        $devices    = Device::orderBy('name')->get(['id', 'name', 'type', 'branch_id']);
        $categories = ['admin', 'api', 'snmp', 'user', 'service', 'other'];
        return view('admin.credentials.form', compact('credential', 'devices', 'categories'));
    }

    public function update(Request $request, Credential $credential)
    {
        $data = $request->validate([
            'title'     => 'required|string|max:255',
            'username'  => 'nullable|string|max:255',
            'password'  => 'nullable|string',   // nullable on edit — blank = don't change
            'url'       => 'nullable|url|max:500',
            'notes'     => 'nullable|string',
            'device_id' => 'nullable|exists:devices,id',
            'category'  => 'required|in:admin,api,snmp,user,service,other',
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $credential->update(array_merge($data, ['updated_by' => Auth::id()]));

        $credential->logAccess('edited', Auth::id(), $request->ip());

        ActivityLog::create([
            'model_type' => 'Credential',
            'model_id'   => $credential->id,
            'action'     => 'updated',
            'changes'    => ['title' => $credential->title],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.credentials.index')
                         ->with('success', "Credential \"{$credential->title}\" updated.");
    }

    public function destroy(Request $request, Credential $credential)
    {
        $title = $credential->title;

        $credential->logAccess('deleted', Auth::id(), $request->ip());

        ActivityLog::create([
            'model_type' => 'Credential',
            'model_id'   => $credential->id,
            'action'     => 'deleted',
            'changes'    => ['title' => $title],
            'user_id'    => Auth::id(),
        ]);

        $credential->delete();

        return redirect()->route('admin.credentials.index')
                         ->with('success', "Credential \"{$title}\" deleted.");
    }

    /**
     * AJAX: reveal password — requires manage-credentials.
     * Returns the decrypted password as JSON and logs the access.
     */
    public function reveal(Request $request, Credential $credential)
    {
        // Permission guard (route middleware handles RBAC, this is a double-check)
        if (!Auth::user()->can('manage-credentials')) {
            abort(403);
        }

        $credential->logAccess('viewed', Auth::id(), $request->ip());

        return response()->json(['password' => $credential->password]);
    }

    /**
     * AJAX: log clipboard copy.
     */
    public function logCopy(Request $request, Credential $credential)
    {
        $credential->logAccess('copied', Auth::id(), $request->ip());
        return response()->json(['ok' => true]);
    }

    /**
     * Generate a secure random password.
     */
    public function generate(): \Illuminate\Http\JsonResponse
    {
        $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}';
        $length   = 20;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return response()->json(['password' => $password]);
    }
}
