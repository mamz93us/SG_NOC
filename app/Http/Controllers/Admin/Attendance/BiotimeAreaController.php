<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\Branch;
use App\Services\Attendance\EmployeeLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Attendance → Areas & Terminals: map each BioTime area to a NOC branch
 * and the wall clock its devices keep. An area's branch also settles emp_codes
 * that match two employees across the colliding Oracle series.
 */
class BiotimeAreaController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance.areas.index', [
            'areas' => BiotimeArea::with(['source:id,name', 'branch:id,name'])->orderBy('biotime_source_id')->orderBy('area_alias')->get(),
            'terminals' => BiotimeTerminal::with('source:id,name')->orderBy('biotime_source_id')->orderBy('terminal_alias')->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'timezones' => $this->timezones(),
        ]);
    }

    public function update(Request $request, BiotimeArea $area, EmployeeLinker $linker): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
        ]);

        $old = $area->only(['branch_id', 'timezone']);
        $area->update($data);

        ActivityLog::create([
            'model_type' => 'BiotimeArea',
            'model_id' => $area->id,
            'action' => 'updated',
            'changes' => ['area_alias' => $area->area_alias, 'old' => $old, 'new' => $data],
            'user_id' => Auth::id(),
        ]);

        @set_time_limit(300);
        $linked = $linker->retryUnlinked($area->source);

        return back()->with('success', "Area \"{$area->area_alias}\" saved."
            .($linked ? " {$linked} previously ambiguous code(s) are now linked." : ''));
    }

    /** @return list<string> */
    private function timezones(): array
    {
        return array_values(array_unique([
            config('app.timezone'),
            'Africa/Cairo',
            'Asia/Riyadh',
            'Asia/Dubai',
            'Asia/Qatar',
            'Asia/Kuwait',
            'Asia/Bahrain',
            'Asia/Muscat',
            'Asia/Amman',
        ]));
    }
}
