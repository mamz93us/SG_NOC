<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ProfileEditRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MyProfileController extends Controller
{
    /**
     * Fields the user can request changes to. Everything else (email, azure_id,
     * status, manager, dates, etc.) is Azure-controlled and not user-editable.
     */
    private const EDITABLE_FIELDS = [
        'name'             => 'Display name',
        'job_title'        => 'Job title',
        'extension_number' => 'Extension',
        'phone'            => 'Phone',
    ];

    public function index(): View
    {
        $user = Auth::user();

        $employee = Employee::with(['branch', 'department', 'manager', 'contact'])
            ->where('email', $user->email)
            ->first();

        $pendingRequest = null;
        $recentRequests = collect();
        if ($employee) {
            $pendingRequest = ProfileEditRequest::where('employee_id', $employee->id)
                ->pending()
                ->latest()
                ->first();
            $recentRequests = ProfileEditRequest::where('employee_id', $employee->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
        }

        $editableFields = self::EDITABLE_FIELDS;

        return view('portal.profile', compact(
            'user', 'employee', 'pendingRequest', 'recentRequests', 'editableFields'
        ));
    }

    public function submitEditRequest(Request $request): RedirectResponse
    {
        $user     = Auth::user();
        $employee = Employee::where('email', $user->email)->first();

        if (!$employee) {
            return back()->with('error', 'Your account is not linked to an employee record. Contact IT to create one.');
        }

        if (ProfileEditRequest::where('employee_id', $employee->id)->pending()->exists()) {
            return back()->with('error', 'You already have a pending update request. Please wait for IT to review it.');
        }

        $validated = $request->validate([
            'name'             => 'nullable|string|max:255',
            'job_title'        => 'nullable|string|max:255',
            'extension_number' => 'nullable|string|max:32',
            'phone'            => 'nullable|string|max:64',
            'note'             => 'nullable|string|max:1000',
        ]);

        // Build a diff of only actually-changed fields.
        $current = [
            'name'             => $employee->name,
            'job_title'        => $employee->job_title,
            'extension_number' => $employee->extension_number,
            'phone'            => $employee->contact?->phone,
        ];

        $changes = [];
        foreach (array_keys(self::EDITABLE_FIELDS) as $field) {
            $new = $validated[$field] ?? null;
            $old = $current[$field] ?? null;
            if ($new !== null && trim((string) $new) !== '' && (string) $new !== (string) $old) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        if (empty($changes)) {
            return back()->with('error', 'No changes detected. Edit at least one field before submitting.');
        }

        ProfileEditRequest::create([
            'employee_id'       => $employee->id,
            'user_id'           => $user->id,
            'requested_changes' => $changes,
            'note'              => $validated['note'] ?? null,
            'status'            => 'pending',
        ]);

        return redirect()->route('portal.profile')
            ->with('success', 'Your update request was sent to IT. You will see it here once reviewed.');
    }
}
