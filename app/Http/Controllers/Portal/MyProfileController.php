<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MyProfileController extends Controller
{
    public const WORKFLOW_TYPE = 'profile_update_phone';

    public function __construct(
        protected WorkflowEngine $workflows,
    ) {}

    public function index(): View
    {
        $user = Auth::user();

        $employee = Employee::with(['branch', 'department', 'manager', 'contact'])
            ->where('email', $user->email)
            ->first();

        $pendingRequest = WorkflowRequest::where('requested_by', $user->id)
            ->where('type', self::WORKFLOW_TYPE)
            ->whereIn('status', ['pending', 'executing', 'manager_input_pending'])
            ->latest()
            ->first();

        $recentRequests = WorkflowRequest::where('requested_by', $user->id)
            ->where('type', self::WORKFLOW_TYPE)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('portal.profile', compact(
            'user', 'employee', 'pendingRequest', 'recentRequests'
        ));
    }

    public function submitEditRequest(Request $request): RedirectResponse
    {
        $user     = Auth::user();
        $employee = Employee::where('email', $user->email)->first();

        if (!$employee) {
            return back()->with('error', 'Your account is not linked to an employee record. Contact IT to create one.');
        }

        // Block second submission while one is already in flight.
        $inFlight = WorkflowRequest::where('requested_by', $user->id)
            ->where('type', self::WORKFLOW_TYPE)
            ->whereIn('status', ['pending', 'executing', 'manager_input_pending'])
            ->exists();

        if ($inFlight) {
            return back()->with('error', 'You already have a pending phone update request. Please wait for IT to review it.');
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:64',
            'note'  => 'nullable|string|max:1000',
        ]);

        $newPhone = trim($validated['phone']);
        $oldPhone = $employee->contact?->phone;

        if ($newPhone === (string) $oldPhone) {
            return back()->with('error', 'That phone number is already on file — nothing to update.');
        }

        $payload = [
            'employee_id'    => $employee->id,
            'employee_email' => $employee->email,
            'employee_name'  => $employee->name,
            'field'          => 'phone',
            'old_value'      => $oldPhone,
            'new_value'      => $newPhone,
            'user_note'      => $validated['note'] ?? null,
        ];

        $this->workflows->createRequest(
            type:        self::WORKFLOW_TYPE,
            payload:     $payload,
            branchId:    $employee->branch_id,
            requestedBy: $user->id,
            title:       "Phone update for {$employee->name}",
            description: "{$employee->name} requests phone change: "
                        . ($oldPhone ?: '—') . " → {$newPhone}"
                        . (!empty($validated['note']) ? "\n\nNote: {$validated['note']}" : ''),
        );

        return redirect()->route('portal.profile')
            ->with('success', 'Your phone update request was sent to IT. You will see it here once reviewed.');
    }
}
