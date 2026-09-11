<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeSource;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\Branch;
use App\Services\Attendance\EmployeeLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Attendance → Areas & Terminals: map each BioTime area — or, for
 * access-control sources, which have no areas, each terminal — to a NOC
 * branch and the wall clock its devices keep. That branch also settles
 * employee codes that match two people across the colliding Oracle series.
 */
class BiotimeAreaController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance.areas.index', [
            'areas' => BiotimeArea::with(['source:id,name', 'branch:id,name'])->orderBy('biotime_source_id')->orderBy('area_alias')->get(),
            'terminals' => BiotimeTerminal::with(['source:id,name,source_type', 'branch:id,name'])->orderBy('biotime_source_id')->orderBy('terminal_alias')->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'timezones' => BiotimeSource::timezoneChoices(),
        ]);
    }

    public function update(Request $request, BiotimeArea $area, EmployeeLinker $linker): RedirectResponse
    {
        $data = $this->validated($request);
        $old = $area->only(['branch_id', 'timezone']);
        $area->update($data);
        $this->log('BiotimeArea', $area->id, ['area_alias' => $area->area_alias, 'old' => $old, 'new' => $data]);

        $linked = $this->relink($linker, $area->source);

        return back()->with('success', "Area \"{$area->area_alias}\" saved."
            .($linked ? " {$linked} previously ambiguous code(s) are now linked." : ''));
    }

    public function updateTerminal(Request $request, BiotimeTerminal $terminal, EmployeeLinker $linker): RedirectResponse
    {
        $data = $this->validated($request);
        $old = $terminal->only(['branch_id', 'timezone']);
        $terminal->update($data);
        $this->log('BiotimeTerminal', $terminal->id, ['terminal_sn' => $terminal->terminal_sn, 'old' => $old, 'new' => $data]);

        $linked = $this->relink($linker, $terminal->source);

        return back()->with('success', 'Terminal "'.($terminal->terminal_alias ?: $terminal->terminal_sn).'" saved.'
            .($linked ? " {$linked} previously ambiguous code(s) are now linked." : ''));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
        ]);
    }

    /** A branch settles codes matching two employees, so ambiguous ones may resolve now. */
    private function relink(EmployeeLinker $linker, ?BiotimeSource $source): int
    {
        @set_time_limit(300);

        return $linker->retryUnlinked($source);
    }

    private function log(string $type, int $id, array $changes): void
    {
        ActivityLog::create([
            'model_type' => $type,
            'model_id' => $id,
            'action' => 'updated',
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
