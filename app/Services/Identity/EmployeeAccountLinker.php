<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Links a person's other employee records to their main one, so the NOC treats
 * them as one person: the People profile, attendance, leave, the Oracle feed and
 * the assistant all go through the main record, and each linked account reads
 * its HR fields from it (Employee::hrSource()).
 *
 * Linking never loses what the linked account knew. Any of these the main record
 * has empty is filled from it, and nothing is overwritten. Without that, a
 * service record from the Oracle HR import as the main one would hand its empty
 * job title and mobile to the mailbox's signature and to the next Azure contact
 * sync, and drop the person from their manager's team in the assistant, which
 * reads reporting lines from the main record.
 */
final class EmployeeAccountLinker
{
    public const FILLED_FROM_LINKED = [
        'job_title', 'department_id', 'oracle_department', 'extension_number', 'ucm_server_id',
        'mobile_phone', 'gender', 'manager_id', 'supervisor_id',
    ];

    /**
     * @param  list<int>  $secondaryIds
     * @return array{linked: list<string>, skipped: list<string>, filled: list<string>}
     */
    public function link(Employee $primary, array $secondaryIds): array
    {
        $result = ['linked' => [], 'skipped' => [], 'filled' => []];

        if ($primary->linked_primary_employee_id) {
            $result['skipped'][] = "{$primary->name} is itself a linked account; pick its main record instead.";

            return $result;
        }
        if ($primary->status === 'terminated') {
            $result['skipped'][] = "{$primary->name} has left the company; a main record must be current.";

            return $result;
        }

        DB::transaction(function () use ($primary, $secondaryIds, &$result) {
            foreach (array_unique(array_map('intval', $secondaryIds)) as $id) {
                $secondary = Employee::find($id);
                $label = $secondary ? ($secondary->email ?: $secondary->name) : "#{$id}";

                if (! $secondary) {
                    $result['skipped'][] = "#{$id} no longer exists.";

                    continue;
                }
                if ($secondary->id === $primary->id) {
                    $result['skipped'][] = "{$label}: a record cannot be linked to itself.";

                    continue;
                }
                if ((int) $secondary->linked_primary_employee_id === $primary->id) {
                    $result['skipped'][] = "{$label} is already linked to {$primary->name}.";

                    continue;
                }
                if ($secondary->linked_primary_employee_id) {
                    $result['skipped'][] = "{$label} is already linked to another record; unlink it first.";

                    continue;
                }
                if ($secondary->status === 'terminated') {
                    $result['skipped'][] = "{$label} has left the company.";

                    continue;
                }

                foreach (self::FILLED_FROM_LINKED as $field) {
                    $value = $secondary->{$field};
                    if (blank($primary->{$field}) && ! blank($value)
                        && ! (in_array($field, ['manager_id', 'supervisor_id'], true) && (int) $value === $primary->id)) {
                        $primary->{$field} = $value;
                        $result['filled'][] = "{$field} from {$label}";
                    }
                }

                // Accounts already linked to this record are the same person too.
                $moved = Employee::where('linked_primary_employee_id', $secondary->id)->get();
                foreach ($moved as $account) {
                    $account->update(['linked_primary_employee_id' => $primary->id]);
                }

                $secondary->update(['linked_primary_employee_id' => $primary->id]);
                $result['linked'][] = $label;

                ActivityLog::create([
                    'model_type' => 'Employee',
                    'model_id' => $secondary->id,
                    'action' => 'linked_account_set',
                    'changes' => [
                        'secondary' => $secondary->email,
                        'secondary_azure_id' => $secondary->azure_id,
                        'primary_employee_id' => $primary->id,
                        'primary' => $primary->email,
                        'moved_accounts' => $moved->pluck('id')->all(),
                    ],
                    'user_id' => Auth::id(),
                ]);
            }

            if ($primary->isDirty()) {
                $primary->save();
            }
        });

        return $result;
    }
}
