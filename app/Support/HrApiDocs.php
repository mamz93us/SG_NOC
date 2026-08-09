<?php

namespace App\Support;

use App\Services\Workflow\EmployeeUpdateRequestService;

/**
 * The HR API reference, as data.
 *
 * The docs page renders this rather than carrying hand-written markup per
 * endpoint, so a field added to a controller is a one-line change here instead
 * of a hunt through 900 lines of Blade — and the page cannot quietly drift out
 * of step with what the API actually accepts.
 *
 * Field rows are [name, type, requirement, description]. `requirement` is one
 * of: required | optional | one-of (any of a group must be present).
 */
class HrApiDocs
{
    /**
     * @return list<array{
     *   id:string, method:string, path:string, summary:string, mirrors:?string,
     *   description:string, notes:list<string>, fields:list<array{0:string,1:string,2:string,3:string}>,
     *   request:?array, response:array, testable:bool
     * }>
     */
    public static function endpoints(): array
    {
        return [
            // ── Onboarding ───────────────────────────────────────────────
            [
                'id' => 'onboarding',
                'method' => 'POST',
                'path' => '/api/hr/onboarding',
                'summary' => 'Raise a new-starter request',
                'mirrors' => 'HR portal → New Employee form',
                'description' => 'Creates a create_user workflow. IT reviews it; on approval NOC creates the Microsoft account, '
                    .'assigns licences and the automatic branch/department/gender groups, creates the employee record, '
                    .'and emails the manager the setup form. The extension, floor groups and business-app accounts follow '
                    .'once the manager answers that form.',
                'notes' => [
                    'Nothing is created at the moment you call this — it raises a request for approval, exactly like the form.',
                    'Identify the manager and supervisor however you hold them: NOC id, work email, or Oracle employee number.',
                    'oracle_emp_no is the reference this person is known by everywhere in NOC. Send it and the HR list reconcile will match them on its first run.',
                    'branch_id defaults to the manager’s branch when omitted; upn_domain defaults to the primary allowed domain.',
                ],
                'fields' => [
                    ['first_name', 'string', 'required', 'Given name. Used to build the UPN.'],
                    ['last_name', 'string', 'required', 'Family name. Used to build the UPN.'],
                    ['gender', 'male | female', 'required', 'Drives gender-based Azure group auto-assignment and the signature template.'],
                    ['start_date', 'date (Y-m-d)', 'required', 'First working day. `suggested_start_date` is accepted as an alias.'],
                    ['manager_id', 'integer', 'one-of', 'NOC employee id of the reporting manager.'],
                    ['manager_email', 'email', 'one-of', 'Work email of the reporting manager.'],
                    ['manager_oracle_emp_no', 'string', 'one-of', 'Oracle employee number of the reporting manager.'],
                    ['oracle_emp_no', 'string', 'optional', 'The new starter’s Oracle employee number. `hr_reference` is accepted as a legacy alias.'],
                    ['upn_domain', 'string', 'optional', 'Email domain, e.g. samirgroup.com. Defaults to the primary allowed domain.'],
                    ['job_title', 'string', 'optional', 'Pushed to the Azure profile.'],
                    ['department_id', 'integer', 'optional', 'NOC department id — see GET /api/hr/reference-data.'],
                    ['department', 'string', 'optional', 'Department name instead of the id. Must match exactly.'],
                    ['branch_id', 'integer', 'optional', 'NOC branch id. Defaults to the manager’s branch.'],
                    ['branch', 'string', 'optional', 'Branch name instead of the id. Must match exactly.'],
                    ['mobile_phone', 'string', 'optional', 'Pushed to the Azure profile.'],
                    ['supervisor_id', 'integer', 'optional', 'Second line of reporting. Also accepts supervisor_email / supervisor_oracle_emp_no.'],
                    ['description', 'string', 'optional', 'Free-text note for IT. `notes` is accepted as an alias.'],
                ],
                'request' => [
                    'first_name' => 'Sara',
                    'last_name' => 'Al-Rashid',
                    'gender' => 'female',
                    'start_date' => '2026-09-01',
                    'oracle_emp_no' => '10432',
                    'job_title' => 'Accountant',
                    'department' => 'Finance',
                    'branch' => 'Jeddah',
                    'mobile_phone' => '+966XXXXXXXXX',
                    'manager_email' => 'manager@samirgroup.com',
                    'description' => 'Replacement for a leaver — needs to be ready on day one.',
                ],
                'response' => [
                    'ok' => true,
                    'workflow_id' => 812,
                    'status' => 'pending',
                    'display_name' => 'Sara Al-Rashid',
                    'oracle_emp_no' => '10432',
                    'proposed_upn' => 'sara.alrashid@samirgroup.com',
                    'branch_id' => 3,
                    'department_id' => 7,
                    'manager' => ['id' => 118, 'name' => 'Preview Manager', 'email' => 'manager@samirgroup.com'],
                    'supervisor' => null,
                    'message' => 'Onboarding request created. IT will review it; the manager setup form is sent after approval.',
                ],
                'testable' => true,
            ],

            // ── Offboarding ──────────────────────────────────────────────
            [
                'id' => 'offboarding',
                'method' => 'POST',
                'path' => '/api/hr/offboarding',
                'summary' => 'Raise a termination request',
                'mirrors' => 'HR portal → Termination form',
                'description' => 'Creates an employee_offboarding workflow, enriches it with live mailbox/OneDrive/group data from '
                    .'Microsoft Graph, and emails the manager the decision form. Deprovisioning only begins once the '
                    .'manager answers — nothing is disabled or deleted at the moment you call this.',
                'notes' => [
                    'Like the form, you identify the leaver and give the termination details only. Their name, work email, branch and manager are read off the employee record.',
                    'The decision form goes to the manager on the record. Send manager_override_id or manager_email only when there is no manager on file, or you deliberately want someone else to decide.',
                    'Returns 503 if the offboarding module is switched off in NOC settings.',
                ],
                'fields' => [
                    ['last_day', 'date (Y-m-d)', 'required', 'Final working day. Must be today or later.'],
                    ['employee_id', 'integer', 'one-of', 'NOC employee id of the leaver.'],
                    ['oracle_emp_no', 'string', 'one-of', 'Oracle employee number of the leaver.'],
                    ['upn', 'email', 'one-of', 'Work email of the leaver.'],
                    ['reason', 'string', 'optional', 'Resignation, end of contract, dismissal…'],
                    ['notes', 'string', 'optional', 'Anything IT should know — handover, disputes, early cut-off.'],
                    ['manager_override_id', 'integer', 'optional', 'Send the decision form to this employee instead of the manager on record.'],
                    ['manager_email', 'email', 'optional', 'Same, by email. Used only when no manager can be resolved.'],
                ],
                'request' => [
                    'oracle_emp_no' => '10432',
                    'last_day' => '2026-09-30',
                    'reason' => 'resignation',
                    'notes' => 'Handing over to the Jeddah finance team.',
                ],
                'response' => [
                    'ok' => true,
                    'workflow_id' => 813,
                    'offboarding_workflow_id' => 96,
                    'status' => 'manager_input_pending',
                    'employee' => [
                        'id' => 421,
                        'name' => 'Sara Al-Rashid',
                        'upn' => 'sara.alrashid@samirgroup.com',
                        'oracle_emp_no' => '10432',
                    ],
                    'manager_email' => 'manager@samirgroup.com',
                    'expected_last_day' => '2026-09-30',
                    'message' => 'Offboarding request created. Manager decision form sent to manager@samirgroup.com. Deprovisioning starts once they respond.',
                ],
                'testable' => true,
            ],

            // ── Employee update ──────────────────────────────────────────
            [
                'id' => 'employee-update',
                'method' => 'POST',
                'path' => '/api/hr/employee-update',
                'summary' => 'Request a change to an employee’s data',
                'mirrors' => 'HR portal → Employee Data Change form',
                'description' => 'Diffs the values you send against the employee’s current values and raises an employee_update '
                    .'workflow containing only what actually differs. Nothing is written to the record or to Azure '
                    .'until IT approves.',
                'notes' => [
                    'Send only the fields you want changed. An omitted field is left alone; a field sent empty is a request to clear it.',
                    'Work email / UPN is deliberately not editable — changing it cascades into the mailbox, Azure sign-in and signatures, so it goes through IT directly.',
                    'Only one change request can be open per employee at a time; a second returns 422 naming the open one.',
                    'oracle_emp_no is an editable field here. Use oracle_emp_no_lookup to *find* the employee, so their number can itself be corrected.',
                ],
                'fields' => array_merge([
                    ['reason', 'string', 'required', 'Why the change is needed. Shown to IT on the approval screen.'],
                    ['employee_id', 'integer', 'one-of', 'NOC employee id.'],
                    ['oracle_emp_no_lookup', 'string', 'one-of', 'Oracle employee number, used to find the employee.'],
                    ['upn', 'email', 'one-of', 'Work email, used to find the employee.'],
                ], self::editableFieldRows()),
                'request' => [
                    'oracle_emp_no_lookup' => '10432',
                    'reason' => 'Promoted to Senior Accountant, moving to the Riyadh office.',
                    'job_title' => 'Senior Accountant',
                    'branch' => 'Riyadh',
                    'manager_email' => 'newmanager@samirgroup.com',
                ],
                'response' => [
                    'ok' => true,
                    'workflow_id' => 814,
                    'status' => 'pending',
                    'employee' => [
                        'id' => 421,
                        'name' => 'Sara Al-Rashid',
                        'upn' => 'sara.alrashid@samirgroup.com',
                        'oracle_emp_no' => '10432',
                    ],
                    'changes' => [
                        ['field' => 'job_title', 'label' => 'Job Title', 'from' => 'Accountant', 'to' => 'Senior Accountant'],
                        ['field' => 'branch_id', 'label' => 'Branch', 'from' => 'Jeddah', 'to' => 'Riyadh'],
                    ],
                    'message' => 'Change request created. Nothing has been changed yet — IT will review it.',
                ],
                'testable' => true,
            ],

            // ── Lookups ──────────────────────────────────────────────────
            [
                'id' => 'employees',
                'method' => 'GET',
                'path' => '/api/hr/employees',
                'summary' => 'Search the employee directory',
                'mirrors' => 'the employee pickers on all three forms',
                'description' => 'Turns a name, work email or Oracle number into the NOC id the write endpoints prefer. Returns '
                    .'directory data only — never credentials.',
                'notes' => [
                    'Defaults to active employees. Pass status=inactive or status=all to widen it.',
                    'Capped at 25 results; raise with limit (max 100).',
                ],
                'fields' => [
                    ['query', 'string', 'optional', 'Partial match on name, work email or Oracle number.'],
                    ['oracle_emp_no', 'string', 'optional', 'Exact Oracle employee number.'],
                    ['upn', 'email', 'optional', 'Exact work email.'],
                    ['status', 'active | inactive | all', 'optional', 'Defaults to active.'],
                    ['limit', 'integer', 'optional', '1–100, default 25.'],
                ],
                'request' => null,
                'response' => [
                    'ok' => true,
                    'count' => 1,
                    'employees' => [[
                        'id' => 421,
                        'name' => 'Sara Al-Rashid',
                        'upn' => 'sara.alrashid@samirgroup.com',
                        'oracle_emp_no' => '10432',
                        'job_title' => 'Accountant',
                        'status' => 'active',
                        'branch' => 'Jeddah',
                        'branch_id' => 3,
                        'department' => 'Finance',
                        'department_id' => 7,
                        'manager' => ['id' => 118, 'name' => 'Preview Manager', 'email' => 'manager@samirgroup.com'],
                        'extension' => '2145',
                    ]],
                ],
                'testable' => false,
            ],
            [
                'id' => 'reference-data',
                'method' => 'GET',
                'path' => '/api/hr/reference-data',
                'summary' => 'Branches, departments, domains and editable fields',
                'mirrors' => 'the dropdowns on the forms',
                'description' => 'Everything needed to map your own values onto NOC ids. Cache it — it changes rarely — but re-read '
                    .'it after IT adds a branch or department.',
                'notes' => [],
                'fields' => [],
                'request' => null,
                'response' => [
                    'ok' => true,
                    'branches' => [['id' => 3, 'name' => 'Jeddah']],
                    'departments' => [['id' => 7, 'name' => 'Finance']],
                    'upn_domains' => [['domain' => 'samirgroup.com', 'is_primary' => true]],
                    'genders' => ['male', 'female'],
                    'editable_employee_fields' => ['job_title' => 'Job Title', 'branch_id' => 'Branch'],
                ],
                'testable' => false,
            ],
            [
                'id' => 'request-status',
                'method' => 'GET',
                'path' => '/api/hr/requests/{workflow_id}',
                'summary' => 'Check what happened to a request',
                'mirrors' => 'the request detail page in the HR portal',
                'description' => 'Where a request you raised has got to, including whether the manager has answered their form. '
                    .'Carries no credentials — the initial password never leaves NOC through this API.',
                'notes' => [
                    'The response shape depends on the request type: create_user adds employee + manager_form, employee_offboarding adds offboarding, employee_update adds changes.',
                ],
                'fields' => [],
                'request' => null,
                'response' => [
                    'ok' => true,
                    'workflow_id' => 812,
                    'type' => 'create_user',
                    'status' => 'awaiting_manager',
                    'title' => 'Onboarding: Sara Al-Rashid',
                    'oracle_emp_no' => '10432',
                    'employee' => [
                        'id' => 421,
                        'display_name' => 'Sara Al-Rashid',
                        'upn' => 'sara.alrashid@samirgroup.com',
                        'extension' => null,
                        'start_date' => '2026-09-01',
                    ],
                    'manager_form' => ['sent_to' => 'manager@samirgroup.com', 'responded' => false],
                ],
                'testable' => false,
            ],
            [
                'id' => 'check-availability',
                'method' => 'GET',
                'path' => '/api/hr/onboarding/check-availability',
                'summary' => 'Preview the email address a new starter would get',
                'mirrors' => 'the live availability check on the onboarding form',
                'description' => 'Runs the same UPN builder provisioning uses, including the numeric suffix applied on a collision, '
                    .'so you can confirm the address before committing to a request.',
                'notes' => [],
                'fields' => [
                    ['first_name', 'string', 'required', ''],
                    ['last_name', 'string', 'required', ''],
                    ['upn_domain', 'string', 'optional', 'Defaults to the primary allowed domain.'],
                ],
                'request' => null,
                'response' => [
                    'ok' => true,
                    'upn' => 'sara.alrashid@samirgroup.com',
                    'exact_taken' => false,
                    'display_name_taken' => false,
                    'existing_upn' => null,
                    'error' => null,
                ],
                'testable' => false,
            ],

            // ── Existing endpoints, unchanged ────────────────────────────
            [
                'id' => 'group-assignment',
                'method' => 'POST',
                'path' => '/api/hr/group-assignment',
                'summary' => 'Log requested group memberships for a user',
                'mirrors' => null,
                'description' => 'Records the groups an employee should belong to, for IT to apply. This endpoint does not push to '
                    .'Azure AD itself.',
                'notes' => [],
                'fields' => [
                    ['upn', 'email', 'required', 'The employee’s work email.'],
                    ['group_names', 'string[]', 'required', 'Non-empty list of group display names.'],
                ],
                'request' => [
                    'upn' => 'sara.alrashid@samirgroup.com',
                    'group_names' => ['Finance – All', 'Jeddah – Staff'],
                ],
                'response' => [
                    'ok' => true,
                    'workflow_id' => 815,
                    'status' => 'completed',
                    'message' => 'Group assignment workflow created.',
                ],
                'testable' => true,
            ],
            [
                'id' => 'device-lookup',
                'method' => 'GET',
                'path' => '/api/hr/device-lookup',
                'summary' => 'Hardware and TeamViewer ID for a user’s device',
                'mirrors' => null,
                'description' => 'Returns the devices currently assigned to a user, with TeamViewer ID, CPU and MAC addresses. '
                    .'Intended for helpdesk integrations that need to remote onto a machine.',
                'notes' => [],
                'fields' => [
                    ['upn', 'email', 'required', 'The employee’s work email.'],
                ],
                'request' => null,
                'response' => [
                    'ok' => true,
                    'employee' => 'Sara Al-Rashid',
                    'devices' => [[
                        'asset_code' => 'JED-LAP-0142',
                        'model' => 'HP EliteBook 840 G9',
                        'teamviewer_id' => '1 234 567 890',
                    ]],
                ],
                'testable' => false,
            ],
        ];
    }

    /**
     * The editable-field rows for the employee-update table, generated from the
     * service so the docs cannot list a field the endpoint rejects.
     *
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    private static function editableFieldRows(): array
    {
        $extra = [
            'department_id' => 'NOC department id. Send `department` (name) instead if you prefer.',
            'branch_id' => 'NOC branch id. Send `branch` (name) instead if you prefer.',
            'manager_id' => 'NOC employee id. Send `manager_email` instead if you prefer.',
            'oracle_emp_no' => 'Corrects the employee’s Oracle number.',
        ];

        $rows = [];
        foreach (EmployeeUpdateRequestService::EDITABLE_FIELDS as $field => $meta) {
            $rows[] = [
                $field,
                in_array($meta['type'], ['department', 'branch', 'employee'], true) ? 'integer' : 'string',
                'optional',
                $extra[$field] ?? "New value for {$meta['label']}.",
            ];
        }

        return $rows;
    }
}
