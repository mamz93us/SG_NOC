<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\EmployeeAsset;
use App\Models\WorkflowRequest;
use App\Models\WorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssetScrapController extends Controller
{
    public function index(Request $request)
    {
        $query = WorkflowRequest::where('type', 'asset_scrap')
            ->with(['requester', 'steps', 'branch'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->paginate(20)->withQueryString();

        $statusCounts = WorkflowRequest::where('type', 'asset_scrap')
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('admin.itam.scrap.index', compact('requests', 'statusCounts'));
    }

    public function create(Request $request)
    {
        $deviceQuery = Device::query()
            ->whereNotIn('status', ['scrapped', 'retired'])
            ->orderBy('asset_code');

        $accessoryQuery = Accessory::query()
            ->whereNotIn('status', ['scrapped', 'retired'])
            ->where('quantity_available', '>', 0)
            ->orderBy('asset_code');

        if ($request->filled('q')) {
            $q = $request->q;
            $deviceQuery->where(function ($w) use ($q) {
                $w->where('asset_code', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%");
            });
            $accessoryQuery->where(function ($w) use ($q) {
                $w->where('asset_code', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%");
            });
        }

        $devices = $deviceQuery->limit(50)->get();
        $accessories = $accessoryQuery->limit(50)->get();

        return view('admin.itam.scrap.create', compact('devices', 'accessories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer|exists:devices,id',
            'accessory_ids' => 'nullable|array',
            'accessory_ids.*' => 'integer|exists:accessories,id',
            'reason' => 'required|string|max:2000',
            'disposal_method' => 'required|in:recycle,donate,destroy,sell,return_to_supplier',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|max:4096',
        ]);

        $deviceIds = $validated['device_ids'] ?? [];
        $accessoryIds = $validated['accessory_ids'] ?? [];

        if (empty($deviceIds) && empty($accessoryIds)) {
            return back()
                ->withInput()
                ->with('error', 'Select at least one device or accessory to scrap.');
        }

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store('scrap-photos', 'public');
            }
        }

        $devices = Device::whereIn('id', $deviceIds)->get();
        $accessories = Accessory::whereIn('id', $accessoryIds)->get();

        $alreadyScrappedDevices = $devices->whereIn('status', ['scrapped', 'retired']);
        if ($alreadyScrappedDevices->isNotEmpty()) {
            return back()->with('error', 'One or more devices are already scrapped or retired.');
        }

        $alreadyScrappedAccessories = $accessories->whereIn('status', ['scrapped', 'retired']);
        if ($alreadyScrappedAccessories->isNotEmpty()) {
            return back()->with('error', 'One or more accessories are already scrapped or retired.');
        }

        $itemCount = $devices->count() + $accessories->count();
        $branchId = $devices->first()->branch_id ?? $accessories->first()->branch_id ?? null;

        if ($itemCount === 1) {
            $first = $devices->first() ?? $accessories->first();
            $title = "Scrap: {$first->asset_code} {$first->name}";
        } else {
            $title = "Scrap: {$itemCount} assets";
        }

        $assetCodes = $devices->pluck('asset_code')
            ->merge($accessories->pluck('asset_code'))
            ->filter()
            ->values()
            ->all();

        $workflow = DB::transaction(function () use (
            $validated, $devices, $deviceIds, $accessoryIds,
            $assetCodes, $photoPaths, $branchId, $title
        ) {
            $wf = WorkflowRequest::create([
                'type' => 'asset_scrap',
                'title' => $title,
                'description' => $validated['reason'],
                'payload' => [
                    'device_ids' => array_values($deviceIds),
                    'accessory_ids' => array_values($accessoryIds),
                    'asset_codes' => $assetCodes,
                    'reason' => $validated['reason'],
                    'disposal_method' => $validated['disposal_method'],
                    'photos' => $photoPaths,
                ],
                'branch_id' => $branchId,
                'requested_by' => Auth::id(),
                'status' => 'pending',
                'current_step' => 1,
                'total_steps' => 2,
            ]);

            WorkflowStep::create([
                'workflow_id' => $wf->id,
                'step_number' => 1,
                'approver_role' => 'it_manager',
                'status' => 'pending',
                'step_type' => 'approval',
            ]);
            WorkflowStep::create([
                'workflow_id' => $wf->id,
                'step_number' => 2,
                'approver_role' => 'super_admin',
                'status' => 'pending',
                'step_type' => 'approval',
            ]);

            foreach ($devices as $device) {
                AssetHistory::record(
                    $device,
                    'scrap_requested',
                    'Scrap requested by '.(Auth::user()?->name ?? 'system'),
                    [
                        'workflow_id' => $wf->id,
                        'reason' => $validated['reason'],
                        'disposal_method' => $validated['disposal_method'],
                    ]
                );
            }

            return $wf;
        });

        return redirect()
            ->route('admin.itam.scrap.show', $workflow->id)
            ->with('success', 'Scrap request submitted. Awaiting approval.');
    }

    public function show(WorkflowRequest $workflow)
    {
        abort_unless($workflow->type === 'asset_scrap', 404);

        $workflow->load(['steps.actor', 'steps.approver', 'logs', 'requester', 'branch']);

        $deviceIds = $workflow->payload['device_ids'] ?? [];
        $accessoryIds = $workflow->payload['accessory_ids'] ?? [];
        $devices = Device::whereIn('id', $deviceIds)->get();
        $accessories = Accessory::whereIn('id', $accessoryIds)->get();

        $canApprove = $workflow->isAwaitingMyApproval(Auth::id())
            && (Auth::user()?->can('approve-scrap') ?? false);

        return view('admin.itam.scrap.show', compact('workflow', 'devices', 'accessories', 'canApprove'));
    }

    public function approve(Request $request, WorkflowRequest $workflow)
    {
        abort_unless($workflow->type === 'asset_scrap', 404);

        $user = Auth::user();
        if (! $workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to approve this step.');
        }

        $request->validate(['comments' => 'nullable|string|max:1000']);

        DB::transaction(function () use ($workflow, $user, $request) {
            $step = $workflow->currentStepRecord();
            $step->update([
                'status' => 'approved',
                'acted_by' => $user->id,
                'acted_at' => now(),
                'comments' => $request->input('comments'),
            ]);

            $deviceIds = $workflow->payload['device_ids'] ?? [];
            $accessoryIds = $workflow->payload['accessory_ids'] ?? [];
            $devices = Device::whereIn('id', $deviceIds)->get();

            foreach ($devices as $device) {
                AssetHistory::record(
                    $device,
                    'scrap_approved',
                    "Step {$step->step_number} ({$step->approverRoleLabel()}) approved by {$user->name}",
                    [
                        'workflow_id' => $workflow->id,
                        'step_number' => $step->step_number,
                        'approver_id' => $user->id,
                    ]
                );
            }

            $nextStep = $workflow->current_step + 1;

            if ($nextStep > $workflow->total_steps) {
                $workflow->update(['status' => 'approved']);

                foreach ($devices as $device) {
                    EmployeeAsset::where('asset_id', $device->id)
                        ->whereNull('returned_date')
                        ->update([
                            'returned_date' => now(),
                            'notes' => 'Closed on scrap approval (workflow #'.$workflow->id.')',
                        ]);

                    $device->update([
                        'status' => 'scrapped',
                        'scrap_workflow_id' => $workflow->id,
                        'storage_location' => null,
                    ]);

                    AssetHistory::record(
                        $device,
                        'scrapped',
                        'Asset scrapped after full approval',
                        [
                            'workflow_id' => $workflow->id,
                            'disposal_method' => $workflow->payload['disposal_method'] ?? null,
                        ]
                    );
                }

                if (! empty($accessoryIds)) {
                    Accessory::whereIn('id', $accessoryIds)->update([
                        'status' => 'scrapped',
                        'quantity_available' => 0,
                        'scrap_workflow_id' => $workflow->id,
                    ]);
                }
            } else {
                $workflow->update(['current_step' => $nextStep]);
            }
        });

        return redirect()
            ->route('admin.itam.scrap.show', $workflow->id)
            ->with('success', 'Step approved.');
    }

    public function reject(Request $request, WorkflowRequest $workflow)
    {
        abort_unless($workflow->type === 'asset_scrap', 404);

        $user = Auth::user();
        if (! $workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to reject this step.');
        }

        $request->validate(['comments' => 'required|string|max:1000']);

        DB::transaction(function () use ($workflow, $user, $request) {
            $step = $workflow->currentStepRecord();
            $step->update([
                'status' => 'rejected',
                'acted_by' => $user->id,
                'acted_at' => now(),
                'comments' => $request->input('comments'),
            ]);

            $workflow->update(['status' => 'rejected']);

            $deviceIds = $workflow->payload['device_ids'] ?? [];
            $devices = Device::whereIn('id', $deviceIds)->get();
            foreach ($devices as $device) {
                AssetHistory::record(
                    $device,
                    'scrap_rejected',
                    "Scrap rejected by {$user->name}: ".$request->input('comments'),
                    ['workflow_id' => $workflow->id]
                );
            }
        });

        return redirect()
            ->route('admin.itam.scrap.show', $workflow->id)
            ->with('success', 'Scrap request rejected.');
    }

    public function print(WorkflowRequest $workflow)
    {
        abort_unless($workflow->type === 'asset_scrap', 404);
        abort_unless($workflow->status === 'approved', 409, 'Disposal certificate is only available for approved scrap requests.');

        $workflow->load(['steps.actor', 'requester', 'branch']);

        $deviceIds = $workflow->payload['device_ids'] ?? [];
        $accessoryIds = $workflow->payload['accessory_ids'] ?? [];
        $devices = Device::with('branch')->whereIn('id', $deviceIds)->get();
        $accessories = Accessory::with('branch')->whereIn('id', $accessoryIds)->get();

        return view('admin.itam.scrap.print', compact('workflow', 'devices', 'accessories'));
    }
}
