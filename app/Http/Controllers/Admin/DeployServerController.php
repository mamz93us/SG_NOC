<?php

namespace App\Http\Controllers\Admin;

/*
 * SECURITY NOTES:
 *  1. The SSH host always comes from the stored row — never from the request.
 *     Only manage-deploy-servers can create or edit a row.
 *  2. Key material is encrypted at rest (model casts) and is never rendered
 *     back into the form; the edit page shows filename + fingerprint only.
 *  3. Secrets are stripped before anything reaches the activity log.
 */

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DeployServer;
use App\Services\Deploy\SshKeyInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeployServerController extends Controller
{
    /** Uploaded keys are a few KB at most; anything bigger is not a key. */
    private const MAX_KEY_BYTES = 16384;

    public function __construct(private readonly SshKeyInspector $inspector) {}

    public function index(): View
    {
        $servers = DeployServer::with(['branch'])
            ->withCount('activeCommands')
            ->orderBy('name')
            ->get();

        return view('admin.deploy.index', compact('servers'));
    }

    public function create(): View
    {
        return view('admin.deploy.form', [
            'server' => new DeployServer(['port' => 22, 'auth_type' => 'key', 'is_active' => true]),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $server = new DeployServer($data);
        $server->created_by = $request->user()->id;
        $server->updated_by = $request->user()->id;

        $this->applyCredentials($request, $server);
        $server->save();

        $this->audit("Created deployment server '{$server->name}' ({$server->target()})", $server);

        return redirect()
            ->route('admin.deploy.servers.show', $server)
            ->with('success', "Server '{$server->name}' created. Use Test connection to verify the credentials.");
    }

    public function show(Request $request, DeployServer $server): View
    {
        $server->load(['branch', 'commands']);

        return view('admin.deploy.show', [
            'server' => $server,
            'runs' => $server->runs()->with('user')->limit(20)->get(),
        ]);
    }

    public function edit(DeployServer $server): View
    {
        return view('admin.deploy.form', [
            'server' => $server,
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, DeployServer $server): RedirectResponse
    {
        $data = $this->validated($request, $server);

        $server->fill($data);
        $server->updated_by = $request->user()->id;

        $this->applyCredentials($request, $server);
        $server->save();

        $this->audit("Updated deployment server '{$server->name}'", $server);

        return redirect()
            ->route('admin.deploy.servers.show', $server)
            ->with('success', "Server '{$server->name}' updated.");
    }

    public function destroy(DeployServer $server): RedirectResponse
    {
        $name = $server->name;
        $server->delete();

        $this->audit("Deleted deployment server '{$name}'", null);

        return redirect()
            ->route('admin.deploy.index')
            ->with('success', "Server '{$name}' deleted.");
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function validated(Request $request, ?DeployServer $existing): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hostname' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'],
            'auth_type' => ['required', 'string', 'in:key,password'],
            'working_directory' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
            'private_key_file' => ['nullable', 'file', 'max:16'],
            'key_passphrase' => ['nullable', 'string', 'max:500'],
            'password' => ['nullable', 'string', 'max:500'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }

    /**
     * Move credentials onto the model.
     *
     * A blank key/password field on edit means "leave what is stored alone" —
     * the form can't round-trip the secret, so an empty value must never be
     * read as "clear it".
     */
    private function applyCredentials(Request $request, DeployServer $server): void
    {
        if ($server->auth_type === 'password') {
            if (filled($request->input('password'))) {
                $server->password = $request->input('password');
            }

            return;
        }

        if (filled($request->input('key_passphrase'))) {
            $server->key_passphrase = $request->input('key_passphrase');
        }

        $file = $request->file('private_key_file');
        if (! $file) {
            return;
        }

        $contents = (string) file_get_contents($file->getRealPath());
        if (strlen($contents) > self::MAX_KEY_BYTES) {
            return;
        }

        // Advisory only — ssh2 in the proxy is the real parser, so an
        // unrecognised format is still stored and proved with Test connection.
        $info = $this->inspector->inspect($contents);

        $server->private_key = $contents;
        $server->key_filename = $file->getClientOriginalName();
        $server->key_format = $info['format'];
        $server->key_fingerprint = $info['fingerprint'] ?: $info['comment'];
    }

    private function audit(string $message, ?DeployServer $server): void
    {
        try {
            ActivityLog::log('DEPLOY_SERVER', $message, 'success', $server?->id ?? 0);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
