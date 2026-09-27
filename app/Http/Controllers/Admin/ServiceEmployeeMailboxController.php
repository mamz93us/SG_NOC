<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Identity\ServiceEmployeeMailboxLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Giving a service employee the mailbox they turn out to have.
 *
 * Two endpoints: one that answers the picker with candidates, and one that
 * carries out whichever kind was chosen. See ServiceEmployeeMailboxLinker for
 * why an Entra account and an employee record are offered in the same list and
 * why only one of them is a "link".
 *
 * Gated on `manage-employees`, the same permission as editing the employee, and
 * the write is a POST so nothing here happens on a GET.
 */
class ServiceEmployeeMailboxController extends Controller
{
    public function __construct(private ServiceEmployeeMailboxLinker $linker) {}

    /** Candidates for the picker, as JSON. */
    public function candidates(Request $request, Employee $employee): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('manage-employees'), 403);

        if ($problem = $this->linker->problemWith($employee)) {
            return response()->json(['problem' => $problem, 'candidates' => []]);
        }

        $request->validate(['q' => 'nullable|string|max:120']);

        return response()->json([
            'problem' => null,
            'candidates' => $this->linker->candidates($employee, $request->query('q')),
        ]);
    }

    /** Link an Entra account, or merge with the employee record that holds the address. */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('manage-employees'), 403);

        $data = $request->validate([
            'kind' => 'required|in:'.ServiceEmployeeMailboxLinker::KIND_ENTRA.','.ServiceEmployeeMailboxLinker::KIND_EMPLOYEE,
            'ref' => 'required|string|max:100',
        ]);

        if ($data['kind'] === ServiceEmployeeMailboxLinker::KIND_ENTRA) {
            $result = $this->linker->linkEntra($employee, $data['ref']);

            if (! $result['ok']) {
                return back()->with('error', implode(' ', $result['problems']));
            }

            return redirect()
                ->route('admin.employees.show', $employee)
                ->with('success', "{$employee->name} now holds {$employee->fresh()->email}. They count as a "
                    .'standard employee from here on, so the contact sync, the home portal and the signature '
                    .'templates all reach them.');
        }

        $other = Employee::find((int) $data['ref']);

        if (! $other) {
            return back()->with('error', 'That employee record no longer exists.');
        }

        $result = $this->linker->mergeWithEmployee($employee, $other);

        if (! $result['ok']) {
            return back()->with('error', 'Could not merge the two records: '.implode(' ', $result['problems']));
        }

        $kept = $result['kept'];

        // The service record may well be the one that was merged away, so send
        // whoever did it to whichever record survived rather than to a 404.
        return redirect()
            ->route('admin.employees.show', $kept)
            ->with('success', "The two records are now one: {$kept->name} holds {$kept->email}. "
                .'Everything that pointed at the duplicate — assets, punches, leave — moved across.');
    }
}
