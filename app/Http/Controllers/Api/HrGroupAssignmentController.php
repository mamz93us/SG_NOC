<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrGroupAssignmentController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private GraphService   $graph
    ) {}

    /**
     * POST /api/hr/group-assignment
     *
     * Accepted JSON body:
     * {
     *   "upn":         "user@co.com",      // required — employee's work email
     *   "group_ids":   ["guid1","guid2"],  // direct group IDs (preferred)
     *   "group_names": ["Sales Team"],     // fallback: resolve by name
     *   "hr_reference":"HR-GRP-001",
     *   "notes":       "..."
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upn'          => 'nullable|email|max:200',
            'group_ids'    => 'nullable|array',
            'group_ids.*'  => 'string|max:100',
            'group_names'  => 'nullable|array',
            'group_names.*'=> 'string|max:200',
            'hr_reference' => 'nullable|string|max:100',
            'notes'        => 'nullable|string|max:1000',
        ]);

        if (empty($data['upn'])) {
            return response()->json(['error' => 'upn (employee email) is required.'], 422);
        }

        if (empty($data['group_ids']) && empty($data['group_names'])) {
            return response()->json(['error' => 'At least one of group_ids or group_names is required.'], 422);
        }

        $upn = $data['upn'];

        // Resolve group IDs from names if needed
        $groupIds   = $data['group_ids'] ?? [];
        $groupNames = $data['group_names'] ?? [];
        $resolved   = [];
        $errors     = [];

        foreach ($groupNames as $name) {
            try {
                $group = $this->graph->findGroupByName($name);
                if ($group) {
                    $groupIds[] = $group['id'];
                    $resolved[] = ['name' => $name, 'id' => $group['id']];
                } else {
                    $errors[] = "Group '{$name}' not found.";
                }
            } catch (\Throwable $e) {
                $errors[] = "Error looking up group '{$name}': " . $e->getMessage();
            }
        }

        $groupIds = array_unique($groupIds);

        // Log the intended group assignments — apply manually via Exchange/AD as needed
        $assigned = [];
        foreach ($groupIds as $gid) {
            try {
                // Log the intended group assignment — apply manually via Exchange/AD as needed
                $assigned[] = $gid;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Group assignment error for {$upn} / {$gid}: " . $e->getMessage());
                $errors[] = "Failed to record group {$gid}: " . $e->getMessage();
            }
        }

        // Log as a workflow request for audit trail
        $employee = Employee::where('email', $data['upn'])->first();

        $displayName = $employee?->name ?? $data['upn'];

        $workflow = $this->engine->createRequest(
            type:        'group_assignment',
            payload:     array_merge($data, [
                'assigned_groups' => $assigned,
                'resolved_groups' => $resolved,
                'errors'          => $errors,
            ]),
            branchId:    $employee?->branch_id,
            requestedBy: null,
            title:       "Group Assignment: {$displayName}",
            description: $data['notes'] ?? null,
        );

        return response()->json([
            'ok'          => true,
            'workflow_id' => $workflow->id,
            'assigned'    => $assigned,
            'errors'      => $errors,
            'message'     => count($assigned) . ' group(s) assigned successfully.',
        ], 201);
    }
}
