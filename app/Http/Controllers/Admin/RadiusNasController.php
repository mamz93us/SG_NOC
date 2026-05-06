<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RadiusNasClient;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

/**
 * Admin CRUD for the RADIUS NAS clients table.
 *
 * Every write triggers a `radmin reload clients` so FreeRADIUS picks up the
 * new row without a full restart. The reload runs through a sudoers-allowed
 * wrapper script (deployment/freeradius/sg-radius-control.sh).
 */
class RadiusNasController extends Controller
{
    public function index()
    {
        $clients = RadiusNasClient::with('branch')
            ->orderBy('branch_id')
            ->orderBy('shortname')
            ->paginate(25);

        return view('admin.radius.nas.index', compact('clients'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.radius.nas.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $data = $this->validateInput($request);

        RadiusNasClient::create($data);

        $reload = $this->reloadFreeRadius();

        return redirect()
            ->route('admin.radius.nas.index')
            ->with('success', 'NAS client added.' . $this->reloadSuffix($reload));
    }

    public function edit(RadiusNasClient $nas)
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.radius.nas.edit', compact('nas', 'branches'));
    }

    public function update(Request $request, RadiusNasClient $nas)
    {
        $data = $this->validateInput($request, $nas->id);

        // Allow secret to be left blank in the edit form to keep the existing one.
        if (empty($data['secret'])) {
            unset($data['secret']);
        }

        $nas->update($data);

        $reload = $this->reloadFreeRadius();

        return redirect()
            ->route('admin.radius.nas.index')
            ->with('success', 'NAS client updated.' . $this->reloadSuffix($reload));
    }

    public function destroy(RadiusNasClient $nas)
    {
        $nas->delete();

        $reload = $this->reloadFreeRadius();

        return redirect()
            ->route('admin.radius.nas.index')
            ->with('success', 'NAS client deleted.' . $this->reloadSuffix($reload));
    }

    public function reload()
    {
        $reload = $this->reloadFreeRadius();

        return redirect()
            ->route('admin.radius.nas.index')
            ->with($reload['ok'] ? 'success' : 'error', $reload['message']);
    }

    private function validateInput(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:radius_nas_clients,nasname'
            . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'nasname'     => "required|string|max:128|{$unique}",
            'shortname'   => 'required|string|max:64',
            'type'        => 'required|in:cisco,aruba,meraki,mikrotik,other',
            'secret'      => $ignoreId ? 'nullable|string|min:6|max:120' : 'required|string|min:6|max:120',
            'description' => 'nullable|string|max:255',
            'branch_id'   => 'nullable|integer|exists:branches,id',
            'is_active'   => 'sometimes|boolean',
        ]);
    }

    /**
     * Trigger FreeRADIUS to re-read the SQL clients table.
     *
     * @return array{ok: bool, message: string}
     */
    private function reloadFreeRadius(): array
    {
        $script = config('radius.control_script', '/usr/local/bin/sg-radius-control');

        $process = new Process(['sudo', '-n', $script, 'reload-clients']);
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'FreeRADIUS reload failed to start: ' . $e->getMessage()];
        }

        if (! $process->isSuccessful()) {
            return [
                'ok'      => false,
                'message' => 'FreeRADIUS reload failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        }

        return ['ok' => true, 'message' => 'FreeRADIUS clients reloaded.'];
    }

    private function reloadSuffix(array $reload): string
    {
        return $reload['ok']
            ? ' FreeRADIUS clients reloaded.'
            : ' (Warning: ' . $reload['message'] . ')';
    }
}
