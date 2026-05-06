<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\IntuneGroup;
use App\Models\IntuneGroupMember;
use App\Models\IntuneGroupPolicy;
use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Services\Identity\GraphService;
use App\Services\PrinterScriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntuneGroupController extends Controller
{
    public function __construct(private GraphService $graph) {}

    private function logGraphFailure(string $operation, \Throwable $e, array $extra = []): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'GraphApi',
                'model_id'   => 0,
                'action'     => 'api_failed',
                'changes'    => array_merge([
                    'service'   => 'Intune',
                    'operation' => $operation,
                    'message'   => mb_substr($e->getMessage(), 0, 1000),
                ], $extra),
                'user_id' => auth()->id(),
            ]);
        } catch (\Throwable) {
            // Never mask the original failure with audit errors.
        }
    }

    /**
     * GET /admin/intune-groups
     */
    public function index()
    {
        $groups = IntuneGroup::with(['branch', 'department'])
            ->withCount(['members', 'policies'])
            ->orderBy('name')
            ->get();

        return view('admin.intune_groups.index', compact('groups'));
    }

    /**
     * GET /admin/intune-groups/create
     */
    public function create()
    {
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        return view('admin.intune_groups.create', compact('branches', 'departments'));
    }

    /**
     * POST /admin/intune-groups
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string|max:500',
            'group_type'     => 'required|in:printer,policy,device,compliance',
            'branch_id'      => 'nullable|exists:branches,id',
            'department_id'  => 'nullable|exists:departments,id',
            'mode'           => 'required|in:create,link',
            'azure_group_id' => 'nullable|string|max:100',
        ]);

        $linking = $data['mode'] === 'link';

        try {
            if ($linking) {
                if (empty($data['azure_group_id'])) {
                    return back()->withInput()->withErrors(['azure_group_id' => 'Please search for and select an Azure AD group.']);
                }
                $azureGroup = $this->graph->getGroup($data['azure_group_id']);
            } else {
                $azureGroup = $this->graph->createGroup($data['name'], $data['description'] ?? '');
            }
        } catch (\Throwable $e) {
            $action = $linking ? 'fetch' : 'create';
            $this->logGraphFailure("group_{$action}", $e, ['name' => $data['name']]);
            return back()->withInput()->withErrors(['graph' => "Azure group {$action} failed: " . $e->getMessage()]);
        }

        $group = IntuneGroup::create([
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'group_type'     => $data['group_type'],
            'branch_id'      => $data['branch_id'] ?? null,
            'department_id'  => $data['department_id'] ?? null,
            'azure_group_id' => $azureGroup['id'] ?? null,
            'sync_status'    => 'synced',
            'last_synced_at' => now(),
        ]);

        $message = $linking
            ? 'Azure AD group "' . $group->name . '" linked successfully.'
            : 'Group "' . $group->name . '" created in Azure AD.';

        return redirect()->route('admin.intune-groups.show', $group)->with('success', $message);
    }

    /**
     * GET /admin/intune-groups/groups/search?q=...
     */
    public function searchGroups(Request $request): JsonResponse
    {
        $q = $request->query('q', '');
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        try {
            $groups = $this->graph->searchGroups($q);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($groups);
    }

    /**
     * GET /admin/intune-groups/{group}
     */
    public function show(IntuneGroup $intuneGroup)
    {
        $intuneGroup->load(['members', 'policies', 'branch', 'department']);
        $printers = Printer::orderBy('printer_name')->get(['id', 'printer_name', 'ip_address']);

        return view('admin.intune_groups.show', [
            'group'    => $intuneGroup,
            'printers' => $printers,
        ]);
    }

    /**
     * DELETE /admin/intune-groups/{group}
     */
    public function destroy(IntuneGroup $intuneGroup): RedirectResponse
    {
        if ($intuneGroup->azure_group_id) {
            $this->graph->deleteGroup($intuneGroup->azure_group_id);
        }

        $intuneGroup->delete();

        return redirect()->route('admin.intune-groups.index')
            ->with('success', 'Group "' . $intuneGroup->name . '" deleted.');
    }

    /**
     * POST /admin/intune-groups/{group}/members
     */
    public function addMember(Request $request, IntuneGroup $intuneGroup): RedirectResponse
    {
        $data = $request->validate([
            'azure_user_id' => 'required|string|max:100',
            'user_upn'      => 'required|string|max:150',
            'display_name'  => 'required|string|max:150',
        ]);

        if (! $intuneGroup->azure_group_id) {
            return back()->withErrors(['group' => 'This group has no Azure Group ID. Please re-sync.']);
        }

        try {
            $this->graph->addUserToGroup($data['azure_user_id'], $intuneGroup->azure_group_id);
        } catch (\Throwable $e) {
            $this->logGraphFailure('add_member', $e, ['group_id' => $intuneGroup->id, 'user_upn' => $data['user_upn']]);
            return back()->withErrors(['graph' => 'Failed to add member: ' . $e->getMessage()]);
        }

        IntuneGroupMember::updateOrCreate(
            ['intune_group_id' => $intuneGroup->id, 'azure_user_id' => $data['azure_user_id']],
            ['user_upn' => $data['user_upn'], 'display_name' => $data['display_name'], 'status' => 'added']
        );

        return back()->with('success', $data['display_name'] . ' added to group.');
    }

    /**
     * DELETE /admin/intune-groups/{group}/members/{userId}
     */
    public function removeMember(IntuneGroup $intuneGroup, string $userId): RedirectResponse
    {
        if ($intuneGroup->azure_group_id) {
            try {
                $this->graph->removeUserFromGroup($userId, $intuneGroup->azure_group_id);
            } catch (\Throwable $e) {
                $this->logGraphFailure('remove_member', $e, ['group_id' => $intuneGroup->id, 'user_id' => $userId]);
                return back()->withErrors(['graph' => 'Failed to remove member: ' . $e->getMessage()]);
            }
        }

        IntuneGroupMember::where('intune_group_id', $intuneGroup->id)
            ->where('azure_user_id', $userId)
            ->update(['status' => 'removed']);

        return back()->with('success', 'Member removed from group.');
    }

    /**
     * GET /admin/intune-groups/users/search?q=...
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = $request->get('q', '');
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        try {
            $users = $this->graph->searchUsers($q);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($users);
    }

    /**
     * POST /admin/intune-groups/{group}/deploy-printer
     */
    public function deployPrinter(Request $request, IntuneGroup $intuneGroup): RedirectResponse
    {
        $data = $request->validate([
            'printer_id' => 'required|exists:printers,id',
        ]);

        if (! $intuneGroup->azure_group_id) {
            return back()->withErrors(['group' => 'This group has no Azure Group ID. Please re-sync.']);
        }

        $printer = Printer::with('branch')->findOrFail($data['printer_id']);
        $driver  = PrinterDriver::findForPrinter($printer, 'windows_x64');
        $ps1     = (new PrinterScriptService())->generateIntunePowerShell($printer, $driver);

        $scriptName = 'SG NOC - ' . $printer->printer_name . ' (Group: ' . $intuneGroup->name . ')';

        try {
            $scriptId = $this->graph->uploadIntuneScript(
                $scriptName,
                $ps1,
                'Deployed via SG NOC to group: ' . $intuneGroup->name
            );

            $this->graph->assignIntuneScriptToGroup($scriptId, $intuneGroup->azure_group_id);
        } catch (\Throwable $e) {
            $this->logGraphFailure('deploy_printer', $e, [
                'group_id'   => $intuneGroup->id,
                'printer_id' => $printer->id,
            ]);
            return back()->withErrors(['graph' => 'Intune deploy failed: ' . $e->getMessage()]);
        }

        IntuneGroupPolicy::create([
            'intune_group_id'   => $intuneGroup->id,
            'policy_type'       => 'printer_script',
            'intune_policy_id'  => $scriptId,
            'policy_name'       => $printer->printer_name,
            'policy_payload'    => [
                'printer_id'  => $printer->id,
                'printer_ip'  => $printer->ip_address,
                'driver_name' => $printer->driver_name ?? ($driver?->driver_name ?? 'Generic / Text Only'),
                'branch'      => $printer->branch?->name,
            ],
            'status' => 'assigned',
        ]);

        return back()->with('success', 'Script for "' . $printer->printer_name . '" deployed and assigned to group.');
    }

    /**
     * POST /admin/intune-groups/{group}/sync-policies
     * Queries Intune for each policy's current assignments and updates local status.
     */
    public function syncPolicies(IntuneGroup $intuneGroup): RedirectResponse
    {
        if (! $intuneGroup->azure_group_id) {
            return back()->withErrors(['group' => 'This group has no Azure Group ID.']);
        }

        $synced = 0;
        foreach ($intuneGroup->policies as $policy) {
            if (! $policy->intune_policy_id) {
                continue;
            }
            try {
                $assignments = $this->graph->getIntuneScriptAssignments($policy->intune_policy_id);
                $groupIds    = array_column(array_column($assignments, 'target'), 'groupId');
                $policy->update([
                    'status' => in_array($intuneGroup->azure_group_id, $groupIds) ? 'assigned' : 'error',
                ]);
                $synced++;
            } catch (\Throwable $e) {
                $policy->update(['status' => 'error']);
            }
        }

        $intuneGroup->update(['sync_status' => 'synced', 'last_synced_at' => now()]);

        return back()->with('success', "Synced {$synced} " . str('policy')->plural($synced) . " from Intune.");
    }

    /**
     * DELETE /admin/intune-groups/{group}/policies/{policy}
     * Unassigns the Intune script from the group and removes the local record.
     */
    public function removePolicy(IntuneGroup $intuneGroup, IntuneGroupPolicy $intuneGroupPolicy): RedirectResponse
    {
        if ($intuneGroupPolicy->intune_policy_id && $intuneGroup->azure_group_id) {
            try {
                $this->graph->unassignIntuneScriptFromGroup(
                    $intuneGroupPolicy->intune_policy_id,
                    $intuneGroup->azure_group_id
                );
            } catch (\Throwable $e) {
                \Log::warning("IntuneGroupController::removePolicy — unassign failed: " . $e->getMessage());
            }
        }

        $name = $intuneGroupPolicy->policy_name;
        $intuneGroupPolicy->delete();

        return back()->with('success', '"' . $name . '" removed from Intune group.');
    }
}
