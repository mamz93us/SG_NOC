<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchLogCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRUD for branch log-collector VM configurations
 * (host, port, bearer token, enabled flag).
 *
 * Use case: when an operator runs install.sh on a new branch VM, that
 * script prints an API token. The operator pastes it here in the NOC
 * UI rather than editing .env files.
 */
class BranchLogCollectorController extends Controller
{
    public function index(): View
    {
        $collectors = BranchLogCollector::orderBy('code')->get();
        return view('admin.branches.log-collectors.index', compact('collectors'));
    }

    public function create(): View
    {
        return view('admin.branches.log-collectors.create', [
            'collector' => new BranchLogCollector(['port' => 8514, 'enabled' => true]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        BranchLogCollector::create($data);

        return redirect()
            ->route('admin.branches.log-collectors.index')
            ->with('success', "Branch '{$data['code']}' added. Click 'Test' to verify connectivity.");
    }

    public function edit(BranchLogCollector $logCollector): View
    {
        return view('admin.branches.log-collectors.edit', ['collector' => $logCollector]);
    }

    public function update(Request $request, BranchLogCollector $logCollector): RedirectResponse
    {
        $data = $this->validated($request, $logCollector);

        // Allow leaving the token blank in the form to mean "keep existing"
        if (empty($data['api_token'])) {
            unset($data['api_token']);
        }

        $logCollector->update($data);

        return redirect()
            ->route('admin.branches.log-collectors.index')
            ->with('success', "Branch '{$logCollector->code}' updated.");
    }

    public function destroy(BranchLogCollector $logCollector): RedirectResponse
    {
        $code = $logCollector->code;
        $logCollector->delete();

        return redirect()
            ->route('admin.branches.log-collectors.index')
            ->with('success', "Branch '{$code}' removed. (Logs on the VM itself are untouched.)");
    }

    /**
     * AJAX: probe the branch's /api/health endpoint and update the row's
     * last_seen_at + status. Returns JSON the UI uses to update the
     * row in place without a full page reload.
     */
    public function test(BranchLogCollector $logCollector): JsonResponse
    {
        if (!$logCollector->api_token) {
            $logCollector->markHealth('error', 'No API token set yet');
            return response()->json([
                'ok'     => false,
                'status' => 'error',
                'error'  => 'No API token configured. Edit the branch and paste the token first.',
            ], 422);
        }

        try {
            $resp = Http::withToken($logCollector->api_token)
                ->timeout(5)
                ->connectTimeout(2)
                ->withOptions(['verify' => false])
                ->get($logCollector->baseUrl() . '/api/health');

            if ($resp->status() === 401) {
                $logCollector->markHealth('unauthorized', 'Token rejected');
                return response()->json(['ok' => false, 'status' => 'unauthorized'], 200);
            }

            if (!$resp->ok()) {
                $logCollector->markHealth('error', "HTTP {$resp->status()}");
                return response()->json([
                    'ok' => false, 'status' => 'error', 'error' => "HTTP {$resp->status()}",
                ], 200);
            }

            $body = $resp->json();
            if (($body['ok'] ?? false) !== true) {
                $logCollector->markHealth('error', 'Unexpected payload');
                return response()->json(['ok' => false, 'status' => 'error'], 200);
            }

            $logCollector->markHealth('healthy');
            return response()->json([
                'ok'           => true,
                'status'       => 'healthy',
                'service'      => $body['service'] ?? null,
                'time'         => $body['time']    ?? null,
                'last_seen_at' => $logCollector->fresh()->last_seen_at?->toDateTimeString(),
            ]);

        } catch (\Throwable $e) {
            $logCollector->markHealth('unreachable', $e->getMessage());
            return response()->json([
                'ok'     => false,
                'status' => 'unreachable',
                'error'  => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * AJAX: random 64-char hex string for the token field. Operator
     * uses this as a generate-on-demand convenience; the value still
     * has to match the BRANCH VM's API_TOKEN to be useful.
     */
    public function generateToken(): JsonResponse
    {
        return response()->json(['token' => bin2hex(random_bytes(32))]);
    }

    private function validated(Request $request, ?BranchLogCollector $existing): array
    {
        return $request->validate([
            'code'      => [
                'required', 'string', 'lowercase', 'min:2', 'max:8',
                'regex:/^[a-z][a-z0-9]+$/',
                Rule::unique('branch_log_collectors', 'code')->ignore($existing?->id),
            ],
            'name'      => ['required', 'string', 'max:100'],
            'host'      => ['required', 'string', 'max:255'],
            'port'      => ['required', 'integer', 'between:1,65535'],
            'api_token' => [$existing ? 'nullable' : 'required', 'string', 'min:16', 'max:255'],
            'enabled'   => ['nullable', 'boolean'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ], [
            'code.regex' => 'Branch code must start with a letter and contain only lowercase letters and digits.',
        ]) + ['enabled' => $request->boolean('enabled')];
    }
}
