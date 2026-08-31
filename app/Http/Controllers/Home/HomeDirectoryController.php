<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The staff directory, in the home portal's own styling.
 *
 * Same data as the public `/contacts` page, re-homed rather than restyled:
 * that view is shared with the NOC and the print layouts, so bending it to a
 * second design would make both harder to change. This one extends
 * layouts.home and lives in the `home.*` namespace like everything else here.
 */
class HomeDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('q'));
        $branchId = $request->input('branch');

        $query = Contact::with('branch')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        return view('home.directory', [
            'contacts' => $query->paginate(60)->withQueryString(),
            'branches' => Branch::orderBy('name')->get(),
            'q' => $search,
            'branchId' => $branchId,
        ]);
    }
}
