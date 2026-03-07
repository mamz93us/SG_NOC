<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExtensionController extends Controller
{
    /**
     * Show extensions page with dropdown for UCM selection
     */
    public function index(Request $request)
    {
        $ucmServers  = UcmServer::active()->orderBy('name')->get();
        $extensions  = [];
        $error       = null;
        $selectedUcm = null;

        $ucmId = $request->get('ucm_id');

        if ($ucmId) {
            $selectedUcm = UcmServer::find($ucmId);

            if ($selectedUcm) {
                try {
                    $api        = new IppbxApiService($selectedUcm);
                    $extensions = $api->listExtensions();
                } catch (\Exception $e) {
                    $error = 'Could not connect to UCM: ' . $e->getMessage();
                }
            }
        } elseif ($ucmServers->count() === 1) {
            // Auto-select if only one UCM
            $selectedUcm = $ucmServers->first();
            try {
                $api        = new IppbxApiService($selectedUcm);
                $extensions = $api->listExtensions();
            } catch (\Exception $e) {
                $error = 'Could not connect to UCM: ' . $e->getMessage();
            }
        }

        return view('admin.extensions.index', compact(
            'ucmServers',
            'extensions',
            'selectedUcm',
            'error'
        ));
    }

    /**
     * Store a new extension on the selected UCM
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'ucm_id'           => 'required|exists:ucm_servers,id',
            'extension'        => 'required|string|max:20',
            'secret'           => 'required|string|min:4|max:100',
            'user_password'    => 'required|string|min:4|max:32',
            'fullname'         => 'nullable|string|max:100',
            'email'            => 'nullable|email|max:150',
            // UCM expects the full cumulative permission strings (per API docs)
            'permission'       => 'required|in:internal,internal-local,internal-local-national,internal-local-national-international',
            'max_contacts'     => 'nullable|integer|min:1|max:10',
            'voicemail_enable' => 'nullable|in:yes,no',
            'call_waiting'     => 'nullable|in:yes,no',
            'dnd'              => 'nullable|in:yes,no',
            'sync_contact'     => 'nullable|in:yes,no',
        ]);

        $ucm = UcmServer::findOrFail($data['ucm_id']);

        try {
            $api = new IppbxApiService($ucm);

            // ── Step 1: Create extension with MINIMUM required fields only ──
            // Sending extra/undocumented fields to addSIPAccountAndUser can trigger -25.
            // Enforcement: user_password matches secret, but vmsecret MUST be purely numeric (for phone dialpad entry).
            $api->createExtension([
                'extension'     => $data['extension'],
                'secret'        => $data['secret'],
                'user_password' => $data['secret'], // ENFORCED identical to secret
                'vmsecret'      => (string) random_int(100000, 999999), // MUST be numeric only
                'permission'    => $data['permission'],
            ]);

            // ── Step 2: Apply all optional settings via updateSIPAccount ──
            // updateSIPAccount is more permissive about extra fields.
            $updatePayload = [
                'max_contacts' => (string) ($data['max_contacts'] ?? 3),
                'hasvoicemail' => $request->has('voicemail_enable') ? 'yes' : 'no',
                'call_waiting' => $request->has('call_waiting')     ? 'yes' : 'no',
                'dnd'          => $request->has('dnd')              ? 'yes' : 'no',
            ];
            if (!empty($data['fullname']))    $updatePayload['fullname']     = $data['fullname'];
            if (!empty($data['email']))       $updatePayload['email']        = $data['email'];
            if ($request->has('sync_contact')) $updatePayload['sync_contact'] = 'yes';

            try {
                $api->updateExtension($data['extension'], $updatePayload);
            } catch (\Exception $e) {
                // Non-fatal — extension was created, optional settings just weren't applied
                \Illuminate\Support\Facades\Log::warning('ExtensionController: post-create update failed', [
                    'extension' => $data['extension'],
                    'error'     => $e->getMessage(),
                ]);
            }


        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Failed to create extension: ' . $e->getMessage());
        }

        // Activity log
        ActivityLog::create([
            'model_type' => 'Extension',
            'model_id'   => 0,
            'action'     => 'created',
            'changes'    => [
                'extension'  => $data['extension'],
                'fullname'   => $data['fullname'] ?? null,
                'email'      => $data['email'] ?? null,
                'permission' => $data['permission'],
                'ucm'        => $ucm->name,
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.extensions.index', ['ucm_id' => $data['ucm_id']])
            ->with('success', "Extension {$data['extension']} created successfully.");
    }

    /**
     * Update an existing extension on the UCM
     */
    public function update(Request $request, string $extension)
    {
        $data = $request->validate([
            'ucm_id'           => 'required|exists:ucm_servers,id',
            'fullname'         => 'nullable|string|max:100',
            'email'            => 'nullable|email|max:150',
            // UCM expects the full cumulative permission strings (per API docs)
            'permission'       => 'required|in:internal,internal-local,internal-local-national,internal-local-national-international',
            'max_contacts'     => 'nullable|integer|min:1|max:10',
            'secret'           => 'nullable|string|min:4|max:100',
            'voicemail_enable' => 'nullable|in:yes,no',
            'call_waiting'     => 'nullable|in:yes,no',
            'dnd'              => 'nullable|in:yes,no',
        ]);

        $ucm = UcmServer::findOrFail($data['ucm_id']);

        $updateData = [
            'permission'   => $data['permission'],
            'max_contacts' => (string) ($data['max_contacts'] ?? 3),
            'hasvoicemail' => $request->has('voicemail_enable') ? 'yes' : 'no',
            'call_waiting' => $request->has('call_waiting')     ? 'yes' : 'no',
            'dnd'          => $request->has('dnd')              ? 'yes' : 'no',
        ];

        // Always send fullname/email so they can be updated (skip only if truly null/not sent)
        if ($data['fullname'] !== null) $updateData['fullname'] = $data['fullname'];
        if ($data['email']    !== null) $updateData['email']    = $data['email'];
        if (!empty($data['secret']))    $updateData['secret']   = $data['secret'];

        try {
            $api = new IppbxApiService($ucm);
            $api->updateExtension($extension, $updateData);
            
            // Explicitly apply changes since updateExtension no longer does it automatically
            $api->applyChanges();
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to update extension: ' . $e->getMessage());
        }

        // Activity log
        ActivityLog::create([
            'model_type' => 'Extension',
            'model_id'   => 0,
            'action'     => 'updated',
            'changes'    => array_merge(['extension' => $extension, 'ucm' => $ucm->name], $updateData),
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.extensions.index', ['ucm_id' => $data['ucm_id']])
            ->with('success', "Extension {$extension} updated successfully.");
    }

    /**
     * Delete an extension from the UCM
     */
    public function destroy(Request $request, string $extension)
    {
        $ucmId = $request->input('ucm_id');
        $ucm   = UcmServer::findOrFail($ucmId);

        try {
            $api = new IppbxApiService($ucm);
            $api->deleteExtension($extension);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete extension: ' . $e->getMessage());
        }

        // Activity log
        ActivityLog::create([
            'model_type' => 'Extension',
            'model_id'   => 0,
            'action'     => 'deleted',
            'changes'    => ['extension' => $extension, 'ucm' => $ucm->name],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.extensions.index', ['ucm_id' => $ucmId])
            ->with('success', "Extension {$extension} deleted successfully.");
    }

    /**
     * Return full extension details for the edit modal (AJAX).
     * Calls getSIPAccount which returns all fields including hasvoicemail,
     * call_waiting, dnd, permission, max_contacts, email, etc.
     */
    public function details(Request $request, string $extension)
    {
        $ucmId = $request->get('ucm_id');
        $ucm   = UcmServer::findOrFail($ucmId);

        try {
            $api  = new IppbxApiService($ucm);
            $data = $api->getExtension($extension);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }

    /**
     * Return Wave / SIP-client credentials for a single extension (AJAX).
     */
    public function wave(Request $request, string $extension)
    {
        $ucmId = $request->get('ucm_id');
        $ucm   = UcmServer::findOrFail($ucmId);

        try {
            $api  = new IppbxApiService($ucm);
            $data = $api->getExtensionWave($extension);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }
}
