<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\People\WorkforceBreakdown;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Attendance → Workforce Breakdown: everybody, counted by Oracle's job
 * category or profession, with the people behind each count.
 *
 * Opens to the same people as Employee Profiles, where each person's category
 * and profession are already shown, and every name here leads to that profile.
 */
class WorkforceBreakdownController extends Controller
{
    public function index(Request $request, WorkforceBreakdown $breakdown): View
    {
        $v = $request->validate([
            'by' => 'nullable|in:'.implode(',', array_keys(WorkforceBreakdown::DIMENSIONS)),
            'pick' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:100',
            'branch' => 'nullable|integer',
            'status' => 'nullable|in:'.implode(',', array_keys(WorkforceBreakdown::STATUS)),
        ]);

        $by = $v['by'] ?? array_key_first(WorkforceBreakdown::DIMENSIONS);
        $filters = [
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'status' => $v['status'] ?? 'active',
        ];

        $groups = $breakdown->groups($by, $filters);
        // A group from another tab, or one the filters have emptied, is no
        // group: show everybody rather than an empty list under a stale name.
        $pick = (string) ($v['pick'] ?? '');
        $picked = $groups->first(fn (array $group) => $group['key'] === $pick);
        $search = trim((string) ($v['q'] ?? ''));

        return view('admin.people.breakdown', [
            'by' => $by,
            'filters' => $filters,
            'search' => $search,
            'groups' => $groups,
            'picked' => $picked,
            'total' => (int) $groups->sum('count'),
            'employees' => $breakdown->employees($by, $picked['key'] ?? null, $filters, $search),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
