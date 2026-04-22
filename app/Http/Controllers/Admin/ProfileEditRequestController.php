<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ProfileEditRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfileEditRequestController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $query = ProfileEditRequest::with(['employee.branch', 'user', 'reviewer'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(25)->withQueryString();

        $counts = [
            'pending'  => ProfileEditRequest::where('status', 'pending')->count(),
            'approved' => ProfileEditRequest::where('status', 'approved')->count(),
            'rejected' => ProfileEditRequest::where('status', 'rejected')->count(),
        ];

        return view('admin.profile-edit-requests.index', compact('requests', 'status', 'counts'));
    }

    public function approve(Request $request, ProfileEditRequest $profileEditRequest): RedirectResponse
    {
        if ($profileEditRequest->status !== 'pending') {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $validated = $request->validate([
            'reviewer_note' => 'nullable|string|max:1000',
        ]);

        $employee = $profileEditRequest->employee;
        if (!$employee) {
            return back()->with('error', 'The employee record for this request no longer exists.');
        }

        DB::transaction(function () use ($profileEditRequest, $employee, $validated) {
            $changes = $profileEditRequest->requested_changes ?? [];
            $employeeFields = ['name', 'job_title', 'extension_number'];

            foreach ($employeeFields as $field) {
                if (isset($changes[$field]['to'])) {
                    $employee->{$field} = $changes[$field]['to'];
                }
            }
            $employee->save();

            // Phone lives on the linked Contact (if any). Create one if missing.
            if (isset($changes['phone']['to'])) {
                $newPhone = $changes['phone']['to'];
                if ($employee->contact_id) {
                    Contact::where('id', $employee->contact_id)->update(['phone' => $newPhone]);
                } else {
                    $nameParts = preg_split('/\s+/', trim($employee->name ?? ''), 2);
                    $contact = Contact::create([
                        'first_name' => $nameParts[0] ?? ($employee->name ?? ''),
                        'last_name'  => $nameParts[1] ?? '',
                        'job_title'  => $employee->job_title,
                        'phone'      => $newPhone,
                        'email'      => $employee->email,
                        'branch_id'  => $employee->branch_id,
                        'source'     => 'profile_edit',
                    ]);
                    $employee->contact_id = $contact->id;
                    $employee->save();
                }
            }

            $profileEditRequest->update([
                'status'        => 'approved',
                'reviewer_id'   => Auth::id(),
                'reviewer_note' => $validated['reviewer_note'] ?? null,
                'reviewed_at'   => now(),
                'applied_at'    => now(),
            ]);
        });

        return back()->with('success', 'Request approved and applied.');
    }

    public function reject(Request $request, ProfileEditRequest $profileEditRequest): RedirectResponse
    {
        if ($profileEditRequest->status !== 'pending') {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $validated = $request->validate([
            'reviewer_note' => 'nullable|string|max:1000',
        ]);

        $profileEditRequest->update([
            'status'        => 'rejected',
            'reviewer_id'   => Auth::id(),
            'reviewer_note' => $validated['reviewer_note'] ?? null,
            'reviewed_at'   => now(),
        ]);

        return back()->with('success', 'Request rejected.');
    }
}
