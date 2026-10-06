<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SaudizationGroup;
use App\Services\People\Saudization;
use App\Services\People\SaudizationByDepartment;
use App\Support\RouteAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Attendance → Saudization: each professional group's share of Saudis
 * against the percentage the ministry requires, and the page HR edits those
 * percentages on.
 *
 * Reading it takes what Workforce Breakdown takes; changing a target takes
 * manage-saudization, because a changed percentage changes which groups the
 * page and the assistant call compliant.
 */
class SaudizationController extends Controller
{
    public function index(Request $request, Saudization $saudization): View
    {
        return view('admin.people.saudization', $saudization->report() + [
            'canEdit' => RouteAccess::allows($request->user(), 'admin.people.saudization.edit'),
        ]);
    }

    /**
     * The same question department by department, Oracle's branch-by-branch
     * department names read as one. Opens to whoever can open the table above.
     */
    public function departments(Request $request, SaudizationByDepartment $byDepartment, Saudization $saudization): View
    {
        $sort = $request->validate(['sort' => 'nullable|in:'.implode(',', array_keys(SaudizationByDepartment::SORTS))])['sort'] ?? 'needs';

        return view('admin.people.saudization-departments', $byDepartment->report($sort) + [
            'sort' => $sort,
            // What the professional groups are short of, to set beside the
            // departments' own sum: the two differ, and the page says why.
            'groupsShortBy' => (int) $saudization->report()['groups']->sum('short_by'),
        ]);
    }

    public function edit(): View
    {
        return view('admin.people.saudization-edit', [
            'groups' => SaudizationGroup::query()->orderBy('sort_order')->orderBy('id')->get(),
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $row = [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'job_category' => 'nullable|string|max:255',
            'required_percent' => 'required|numeric|min:0|max:100',
            'next_percent' => 'nullable|numeric|min:0|max:100',
            'next_from' => 'nullable|date_format:Y-m',
            'future_percent' => 'nullable|numeric|min:0|max:100',
            'future_from' => 'nullable|date_format:Y-m',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ];

        // The blank row at the foot of the form is a group only once it has a name.
        $new = array_filter((array) $request->input('new', []), fn ($value) => $value !== null && $value !== '');
        $adding = filled($new['name_ar'] ?? null);

        if (! $adding && $new !== []) {
            throw ValidationException::withMessages(['new.name_ar' => 'Give the new group a name, or clear its row.']);
        }

        $rules = ['groups' => 'nullable|array', 'delete' => 'nullable|array', 'delete.*' => 'integer'];
        foreach ($row as $field => $rule) {
            $rules["groups.*.{$field}"] = $rule;
            if ($adding) {
                $rules["new.{$field}"] = $rule;
            }
        }

        $data = $request->validate($rules, [], [
            'groups.*.name_ar' => 'group name', 'groups.*.required_percent' => 'current percentage',
            'groups.*.next_percent' => 'upcoming percentage', 'groups.*.future_percent' => 'future percentage',
            'groups.*.next_from' => 'upcoming month', 'groups.*.future_from' => 'future month',
            'new.name_ar' => 'group name', 'new.required_percent' => 'current percentage',
        ]);

        $delete = array_map('intval', $data['delete'] ?? []);
        $rows = collect($data['groups'] ?? [])->reject(fn ($fields, $id) => in_array((int) $id, $delete, true));
        $all = $adding ? $rows->values()->push($data['new']) : $rows->values();

        // One job category belongs to one group, or its people are counted twice.
        $twice = $all->pluck('job_category')->filter()->map(fn ($c) => mb_strtolower(trim($c)))->duplicates()->unique();
        if ($twice->isNotEmpty()) {
            throw ValidationException::withMessages([
                'groups' => 'Each job category can be counted by one group only. Chosen more than once: '.$twice->implode(', ').'.',
            ]);
        }

        DB::transaction(function () use ($rows, $delete, $data, $adding) {
            SaudizationGroup::query()->whereIn('id', $delete)->get()->each->delete();

            foreach (SaudizationGroup::query()->whereIn('id', $rows->keys())->get() as $group) {
                $group->update($this->attributes($rows[$group->id], $group->sort_order));
            }

            if ($adding) {
                SaudizationGroup::create($this->attributes($data['new'], (int) SaudizationGroup::max('sort_order') + 10));
            }
        });

        return redirect()
            ->route(RouteAccess::allows($request->user(), 'admin.people.saudization') ? 'admin.people.saudization' : 'admin.people.saudization.edit')
            ->with('success', 'Saudization targets saved.');
    }

    /**
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    private function attributes(array $fields, int $sortOrder): array
    {
        $percent = fn (string $key) => ($fields[$key] ?? '') === '' || ($fields[$key] ?? null) === null ? null : round((float) $fields[$key], 2);
        // A month from the form: the table says "October 2026", never a day.
        $month = fn (string $key) => filled($fields[$key] ?? null) ? $fields[$key].'-01' : null;

        $attributes = [
            'name_ar' => trim((string) $fields['name_ar']),
            'name_en' => filled($fields['name_en'] ?? null) ? trim((string) $fields['name_en']) : null,
            'job_category' => filled($fields['job_category'] ?? null) ? trim((string) $fields['job_category']) : null,
            'required_percent' => $percent('required_percent'),
            'sort_order' => filled($fields['sort_order'] ?? null) ? (int) $fields['sort_order'] : $sortOrder,
        ];

        // A percentage with no month is kept; a month with no percentage is nothing.
        foreach (['next', 'future'] as $step) {
            $attributes[$step.'_percent'] = $percent($step.'_percent');
            $attributes[$step.'_from'] = $attributes[$step.'_percent'] === null ? null : $month($step.'_from');
        }

        return $attributes;
    }

    /** The job categories Oracle holds for anybody, for the group's dropdown. */
    private function categories(): array
    {
        return Employee::query()->whereNotNull('oracle_job_category')->distinct()
            ->orderBy('oracle_job_category')->pluck('oracle_job_category')->all();
    }
}
